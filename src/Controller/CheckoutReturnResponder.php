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
final class CheckoutReturnResponder
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly SessionWriterInterface $sessionWriter,
        private readonly LoggerInterface $logger = new NullLogger(),
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

        $context->set('requiresCapture', $resolution->requiresCapture);
        $this->dispatcher->dispatch(new CheckoutReturnCompletedEvent($context, $resolution));
        $this->dispatcher->dispatch(new PaymentAuthorizedEvent(
            $context,
            (string) $resolution->authorizationId,
            (string) ($resolution->providerOrderId ?? ''),
            $resolution->amount,
            $resolution->currency,
        ));

        $orderId = $this->resolveOrderId($context, $contract);
        if ($orderId !== null) {
            $this->sessionWriter->writeSessChallenge($orderId);
        }
        return $orderId;
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
