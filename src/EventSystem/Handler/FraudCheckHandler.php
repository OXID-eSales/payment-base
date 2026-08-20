<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Adapter\Response\FraudCheckResponse;
use OxidEsales\PaymentBase\Service\FraudCheckServiceInterface;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;

/**
 * Handles fraud checking on payment authorization.
 *
 * Sprint 2: Performs fraud check via FraudCheckServiceInterface.
 * The interface is in payment-base, but implementations (e.g., Stripe Radar)
 * are in the provider modules.
 *
 * Binary pass/fail only (no manual review):
 * - Passed: Fulfills TYPE_FRAUD_CHECK condition
 * - Failed: Fails the contract (contract will be cancelled)
 *
 * Can be disabled via configuration. When disabled, the condition is
 * immediately fulfilled without checking fraud.
 *
 * @since 1.0.0
 */
class FraudCheckHandler implements HandlerInterface
{
    private const REASON_UNSCREENED = 'unscreened';

    /**
     * @param bool $failOpenOnCheckError Whether an order may proceed when the fraud
     *        check could not be executed at all (PSP outage, bad credentials).
     *        Defaults to true, preserving the historical business outcome — but the
     *        contract now records that no screening happened instead of a forged
     *        pass. Set false to block unscreenable orders. Sprint 133 (F1).
     */
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly FraudCheckServiceInterface $fraudCheckService,
        private readonly bool $enabled = true,
        private readonly bool $failOpenOnCheckError = true,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentAuthorizedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentAuthorizedEvent) {
            // Sprint 133 · Story 16 (F16): a silent return here means the fraud
            // condition is never fulfilled and the contract stalls with the
            // customer's money authorised — a wiring bug, not a normal condition.
            $this->logger->warning('FraudCheckHandler received an unexpected event type; skipping', [
                'expected' => PaymentAuthorizedEvent::class,
                'received' => $event::class,
            ]);

            return;
        }

        $context = $event->getContext();
        $contract = $context->get('contract');

        if (!$contract instanceof PaymentContractInterface) {
            $this->logger->warning('FraudCheckHandler got an event with no contract in context; skipping', [
                'event' => $event::class,
            ]);

            return;
        }

        if (!$this->enabled) {
            // When disabled, immediately fulfill condition without checking fraud
            $contract->fulfillCondition(
                ContractCondition::TYPE_FRAUD_CHECK,
                [
                    'skipped' => true,
                    'reason' => 'Fraud check disabled in configuration',
                ]
            );
            $this->contractRepository->save($contract);
            return;
        }

        // Perform fraud check
        $result = $this->fraudCheckService->check($contract);

        if (!$result->isScreened()) {
            $this->recordUnscreened($contract, $result);
            $this->contractRepository->save($contract);
            return;
        }

        if ($result->isSuccessful()) {
            // Passed: Fulfill the fraud check condition
            $contract->fulfillCondition(
                ContractCondition::TYPE_FRAUD_CHECK,
                [
                    'checkedAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'screened' => true,
                    'passed' => true,
                    'score' => $result->score,
                ]
            );
        } else {
            // Failed: Fail the contract
            $contract->fail(sprintf(
                'Fraud check failed (score: %.2f): %s',
                $result->score,
                $result->reason
            ));
        }

        $this->contractRepository->save($contract);
    }

    /**
     * Record that screening did not happen — without inventing a score.
     *
     * An execution error is subject to the fail-open policy; "nothing to screen"
     * (no payment intent, or a payment method the provider does not score) always
     * proceeds, because there is no outage to protect against.
     */
    private function recordUnscreened(
        PaymentContractInterface $contract,
        FraudCheckResponse $result
    ): void {
        $isCheckError = $result->errorMessage !== null;

        if ($isCheckError && !$this->failOpenOnCheckError) {
            $contract->fail(sprintf(
                'Fraud check could not be executed: %s',
                (string) $result->errorMessage
            ));

            return;
        }

        $contract->fulfillCondition(
            ContractCondition::TYPE_FRAUD_CHECK,
            [
                'checkedAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'screened' => false,
                'passed' => false,
                'reason' => $isCheckError ? 'check_error' : ($result->reason ?? self::REASON_UNSCREENED),
                'error' => $result->errorMessage,
            ]
        );
    }
}
