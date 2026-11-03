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
use OxidEsales\PaymentBase\Math\Money\MinorUnitConverter;
use OxidEsales\PaymentBase\Math\Money\Money;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\PaymentCaptureStatusQueryInterface;
use Psr\Log\LoggerInterface;

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
 * VR-25 D3: every dependency is required. The container wires them all in
 * payment-base itself (broker, capture-status query - a provider replaces the
 * latter with its own), so the handler never silently no-ops.
 */
class RefundIntentHandler
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventBrokerInterface $broker,
        private readonly PaymentCaptureStatusQueryInterface $captureStatusQuery,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof RefundIntentEventInterface) {
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
            if (!$this->withinRemainingRefundable($contract, $intent)) {
                return null;
            }
            return new RefundRequestedEvent($context, $intent->getAmount(), $intent->getReason());
        }

        return null;
    }

    /**
     * VR-21: a refund of captured money is bounded by what is left to refund. Unknown when no capture amount was
     * recorded - then the provider is the bound, as before.
     */
    private function withinRemainingRefundable(
        PaymentContractInterface $contract,
        RefundIntentEventInterface $intent,
    ): bool {
        $requested = $intent->getAmount();
        $remaining = $contract->getRemainingRefundableAmount();
        if ($requested === null || $remaining === null || ($requested > 0.0 && Money::atMost($requested, $remaining))) {
            return true;
        }

        $this->logger->error('[RefundIntentHandler] refund amount outside the remaining refundable - refusing to act', [
            'orderId'             => $intent->getOrderId(),
            'contractId'          => $contract->getId(),
            'remainingRefundable' => $remaining,
            'requestedRefund'     => $requested,
        ]);

        return false;
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
        $keptAmount = self::roundCurrency($authorized - $refund, $contract->getCurrency());
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
        // Correlation keys travel along (e.g. opalreturns' returnId), but never
        // replace what payment-base decided: the base keys win (VR-21).
        $context = new EventContext(array_merge($intent->getCorrelationContext(), $base));
        $context->setContract($contract);
        return $context;
    }

    private static function isFullAmount(float $requested, float $authorized): bool
    {
        return Money::equals($requested, $authorized);
    }

    private static function isPositiveAndWithinAuthorized(float $requested, float $authorized): bool
    {
        return $requested > 0.0 && Money::atMost($requested, $authorized);
    }

    private static function roundCurrency(float $amount, string $currency): float
    {
        return round($amount, MinorUnitConverter::decimalsFor($currency));
    }
}
