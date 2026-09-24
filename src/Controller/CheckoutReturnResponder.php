<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Controller;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Return\CheckoutReturnCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Shared post-PSP-return responder (Sprint E).
 *
 * The single place the provider-neutral return-flow steps live. Each
 * provider's controller (Stripe's {@see \OxidEsales\Payments\Stripe\Controller\StripeOrderController},
 * PayPal's {@see \OxidEsales\Payments\PayPal\Controller\PayPalOrderController})
 * does its PSP-specific input validation (Stripe's contract_token,
 * checkoutSessionId; PayPal's approval-return params), loads the
 * contract, then hands off to this responder to:
 *
 *   1. Build an `EventContext` carrying providerName + contract + any
 *      PSP-specific extras the caller passed in.
 *   2. Call the resolver to translate the PSP outcome into a
 *      `ReturnResolution`.
 *   3. On success, dispatch `CheckoutReturnCompletedEvent` and
 *      `PaymentAuthorizedEvent` so the shared handler chain transitions
 *      the contract, commits the order, stamps OXPAID, and writes the
 *      transaction row.
 *   4. Return the orderId the shared handlers wrote into the context
 *      (fallback to `contract->getOrderId()`), or `null` on failure.
 *
 * Provider-agnostic — no knowledge of Stripe, PayPal, or OPC.
 */
class CheckoutReturnResponder
{
    /**
     * MOL-17: the repository is what lets the return leg yield to a newer state when its own copy
     * turned out stale. Optional so a consumer that wires the responder without it keeps working - it
     * then behaves as before (a stale save surfaces as an error).
     */
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly SessionWriterInterface $sessionWriter,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ContractRepositoryInterface $contracts = null,
    ) {
    }

    /**
     * @param array<string, mixed> $extraContextKeys
     *        Provider-specific context entries (e.g. Stripe's
     *        `checkoutSessionId`, `contract_token`). Merged into the
     *        base context before the resolver runs.
     * @return string|null orderId on success, null on resolver failure
     *                     or exception.
     */
    public function respond(
        string $providerName,
        PaymentContractInterface $contract,
        ReturnResolverInterface $resolver,
        array $extraContextKeys = [],
    ): ?string {
        $context = $this->buildContext($providerName, $contract, $extraContextKeys);

        try {
            $resolution = $resolver->resolve($contract, $context);
        } catch (Throwable $e) {
            $this->logger->warning('[CheckoutReturnResponder] resolver threw', [
                'providerName' => $providerName,
                'contractId' => $contract->getId(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$resolution->isSuccessful()) {
            $this->logger->info('[CheckoutReturnResponder] resolver returned failure', [
                'providerName' => $providerName,
                'contractId' => $contract->getId(),
                'errorCode' => $resolution->errorCode,
            ]);
            return null;
        }

        try {
            $this->dispatchReturn($context, $resolution);
        } catch (StaleContractException $e) {
            return $this->settleOnNewerContract($providerName, $contract, $resolution, $extraContextKeys, $e->getMessage());
        }

        return $this->finish($context, $contract);
    }

    private function dispatchReturn(EventContext $context, ReturnResolution $resolution): void
    {
        $context->set('requiresCapture', $resolution->requiresCapture);
        $this->dispatcher->dispatch(new CheckoutReturnCompletedEvent($context, $resolution));
        $this->dispatcher->dispatch(new PaymentAuthorizedEvent(
            $context,
            (string) $resolution->authorizationId,
            (string) ($resolution->providerOrderId ?? ''),
            $resolution->amount,
            $resolution->currency,
        ));
    }

    private function finish(EventContext $context, PaymentContractInterface $contract): ?string
    {
        $orderId = $this->resolveOrderId($context, $contract);
        if ($orderId !== null) {
            $this->sessionWriter->writeSessChallenge($orderId);
        }

        return $orderId;
    }

    /**
     * MOL-17: the shopper's return leg and the PSP webhook race on the contract. When a handler's save
     * is refused because the row moved on, the webhook has been here already. Reload: a committed or
     * fulfilled contract IS the outcome this leg wanted - report its order and stop; a contract still
     * open gets the handler chain once more on the fresh copy; a second refusal is given up on (the
     * webhook completes the order, the shopper sees the pending notice).
     *
     * @param array<string, mixed> $extraContextKeys
     */
    private function settleOnNewerContract(
        string $providerName,
        PaymentContractInterface $stale,
        ReturnResolution $resolution,
        array $extraContextKeys,
        string $cause,
    ): ?string {
        $fresh = $this->contracts?->findById((string) $stale->getId());
        if ($fresh === null) {
            $this->logger->warning('[CheckoutReturnResponder] stale contract and no fresh copy to fall back on', [
                'contractId' => $stale->getId(),
                'error' => $cause,
            ]);

            return null;
        }

        if ($fresh->getState()->isCommitted() || $fresh->getState()->isFulfilled()) {
            $this->logger->info('[CheckoutReturnResponder] return leg yielded to the webhook', [
                'contractId' => $fresh->getId(),
                'state' => $fresh->getStateValue(),
            ]);

            return $this->finish($this->buildContext($providerName, $fresh, $extraContextKeys), $fresh);
        }

        $context = $this->buildContext($providerName, $fresh, $extraContextKeys);
        try {
            $this->dispatchReturn($context, $resolution);
        } catch (StaleContractException $again) {
            $this->logger->warning('[CheckoutReturnResponder] contract changed twice during the return leg; leaving it to the webhook', [
                'contractId' => $fresh->getId(),
                'error' => $again->getMessage(),
            ]);

            return null;
        }

        return $this->finish($context, $fresh);
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function buildContext(
        string $providerName,
        PaymentContractInterface $contract,
        array $extras,
    ): EventContext {
        $base = array_merge(
            [
                'providerName' => $providerName,
                'contract_id' => $contract->getId(),
                'contractId' => $contract->getId(),
            ],
            $extras,
        );
        $context = new EventContext($base);
        $context->setContract($contract);
        return $context;
    }

    private function resolveOrderId(
        EventContext $context,
        PaymentContractInterface $contract,
    ): ?string {
        $fromContext = $context->get('orderId');
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }
        $fromContract = $contract->getOrderId();
        return is_string($fromContract) && $fromContract !== '' ? $fromContract : null;
    }
}
