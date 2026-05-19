<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Broker\EventBrokerInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundIntentEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\PaymentCaptureStatusQueryInterface;
use OxidEsales\PaymentBase\Service\UnknownPaymentCaptureStatusQuery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Routes {@see RefundIntentEventInterface} events to the right provider-request
 * verb (refund / cancel-authorization / partial-capture) based on the
 * payment contract's current state.
 *
 * Sprint 03 (2026-05-19): lifted verbatim from opalreturns'
 * `PaymentBaseRefundBrokerListener`. The decision tree is unchanged;
 * only the home is. Putting it in payment-base means *any* consumer
 * (returns, admin refund button, future modules) gets the same
 * decision by emitting an intent — no copy needed on the consumer side.
 *
 * Branching rules (mirrors the prior listener):
 *
 *   - state `authorized` — explicit hold-only state:
 *       · refund == authorized → cancel the open authorization.
 *       · 0 < refund < authorized → partial capture of (authorized − refund);
 *         PSP captures only the kept portion and releases the rest of the hold.
 *       · refund ≤ 0 or refund > authorized → out of range; log + skip.
 *   - state `committed` / `fulfilled`:
 *       · PSP says captured → refund.
 *       · PSP says authorized-only → route through the AUTHORIZED branch.
 *       · PSP status unknown → conservative fallback to refund; the PSP
 *         will reject if it really hasn't captured, surfacing a visible error.
 *   - any other state → unsupported; log + skip.
 *
 * All payment-base service dependencies are optional (nullable) so the
 * handler can be registered even in slimmed-down installs; in that
 * case it no-ops and the manual-refund path remains available.
 */
class RefundIntentHandler
{
    /**
     * Currency epsilon for full-sum equality checks.
     * Half a cent — same constant used on the contract aggregate.
     */
    private const FULL_SUM_EPSILON = 0.005;

    private PaymentCaptureStatusQueryInterface $captureStatusQuery;
    private LoggerInterface $logger;

    public function __construct(
        private readonly ?ContractRepositoryInterface $contractRepository = null,
        private readonly ?EventBrokerInterface $broker = null,
        ?PaymentCaptureStatusQueryInterface $captureStatusQuery = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->captureStatusQuery = $captureStatusQuery ?? new UnknownPaymentCaptureStatusQuery();
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof RefundIntentEventInterface) {
            return;
        }
        if ($this->contractRepository === null || $this->broker === null) {
            return;
        }

        $orderId = $event->getOrderId();
        if ($orderId === '') {
            $this->logger->warning('[RefundIntentHandler] intent without orderId', [
                'correlation' => $event->getCorrelationContext(),
            ]);
            return;
        }

        $contract = $this->contractRepository->findByOrderId($orderId);
        if ($contract === null) {
            $this->logger->warning('[RefundIntentHandler] no payment contract for order', [
                'orderId'     => $orderId,
                'correlation' => $event->getCorrelationContext(),
            ]);
            return;
        }

        $providerEvent = $this->buildProviderRequest($contract, $event);
        if ($providerEvent === null) {
            $this->logger->warning('[RefundIntentHandler] intent on contract that is neither captured nor authorized', [
                'orderId'    => $orderId,
                'contractId' => $contract->getId(),
                'state'      => $contract->getStateValue(),
                'captured'   => $contract->getCapturedAmount(),
            ]);
            return;
        }

        $this->broker->dispatch($providerEvent);
    }

    private function buildProviderRequest(
        PaymentContractInterface $contract,
        RefundIntentEventInterface $intent,
    ): ?AbstractProviderRequestEvent {
        $context = $this->buildEventContext($contract, $intent);
        $state   = $contract->getState();

        if ($state->isAuthorized()) {
            return $this->buildAuthorizedBranchEvent($contract, $intent, $context);
        }

        if ($state->isCommitted() || $state->isFulfilled()) {
            $captured = $this->captureStatusQuery->isPaymentCaptured($contract);
            if ($captured === false) {
                return $this->buildAuthorizedBranchEvent($contract, $intent, $context);
            }
            return new RefundRequestedEvent($context, $intent->getAmount(), $intent->getReason());
        }

        return null;
    }

    private function buildAuthorizedBranchEvent(
        PaymentContractInterface $contract,
        RefundIntentEventInterface $intent,
        EventContext $context,
    ): ?AbstractProviderRequestEvent {
        $authorized = $contract->getAmount();
        $refund     = $intent->getAmount() ?? 0.0;

        if (!self::isPositiveAndWithinAuthorized($refund, $authorized)) {
            $this->logger->error(
                '[RefundIntentHandler] refund amount outside authorized hold — refusing to act',
                [
                    'orderId'          => $intent->getOrderId(),
                    'contractId'       => $contract->getId(),
                    'authorizedAmount' => $authorized,
                    'requestedRefund'  => $refund,
                ],
            );
            return null;
        }

        if (self::isFullAmount($refund, $authorized)) {
            return new CancelAuthorizationRequestedEvent($context);
        }

        // Partial: capture only the kept portion; PSP voids the rest of the hold.
        $keptAmount = self::roundCurrency($authorized - $refund);
        $context->set('amount', $keptAmount);
        return new CaptureRequestedEvent($context, $keptAmount, 'return_partial_capture');
    }

    private function buildEventContext(
        PaymentContractInterface $contract,
        RefundIntentEventInterface $intent,
    ): EventContext {
        $base = [
            'providerName' => $contract->getProvider(),
            'orderId'      => $intent->getOrderId(),
            'contractId'   => $contract->getId(),
            'amount'       => $intent->getAmount(),
            'reason'       => $intent->getReason(),
        ];
        // Correlation overlay: intent-supplied keys overlay the base, so
        // callers can attach (e.g.) returnId without us caring what it is.
        $context = new EventContext(array_merge($base, $intent->getCorrelationContext()));
        $context->setContract($contract);
        return $context;
    }

    private static function isFullAmount(float $requested, float $authorized): bool
    {
        return abs($requested - $authorized) < self::FULL_SUM_EPSILON;
    }

    private static function isPositiveAndWithinAuthorized(float $requested, float $authorized): bool
    {
        return $requested > 0.0 && $requested <= ($authorized + self::FULL_SUM_EPSILON);
    }

    private static function roundCurrency(float $amount): float
    {
        return round($amount, 2);
    }
}
