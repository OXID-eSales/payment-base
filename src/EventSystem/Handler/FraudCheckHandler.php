<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FraudCheckServiceInterface;

/**
 * Handles fraud checking on payment authorization.
 *
 * Sprint 2: Performs fraud check via FraudCheckServiceInterface.
 * The interface is in payment-component, but implementations (e.g., Stripe Radar)
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
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly FraudCheckServiceInterface $fraudCheckService,
        private readonly bool $enabled = true
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentAuthorizedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentAuthorizedEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->get('contract');

        if (!$contract instanceof PaymentContractInterface) {
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

        if ($result->isPassed()) {
            // Passed: Fulfill the fraud check condition
            $contract->fulfillCondition(
                ContractCondition::TYPE_FRAUD_CHECK,
                [
                    'checkedAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
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
}
