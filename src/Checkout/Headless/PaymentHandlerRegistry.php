<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\PaymentBase\Adapter\ContractFirstPaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;

/**
 * Collects the `oe.payment.handler` tagged services and keeps the ones that
 * declare themselves contract-first ({@see ContractFirstPaymentHandlerInterface});
 * the one-page checkout's standard handler for the shop's own payments is
 * tagged the same way and must not be mistaken for a provider.
 *
 * @since 3.0.0
 */
final class PaymentHandlerRegistry implements PaymentHandlerRegistryInterface
{
    /** @var list<ContractFirstPaymentHandlerInterface> */
    private array $handlers = [];

    /**
     * @param iterable<PaymentHandlerInterface> $handlers the `oe.payment.handler` tagged services
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            if ($handler instanceof ContractFirstPaymentHandlerInterface) {
                $this->handlers[] = $handler;
            }
        }
    }

    public function forPaymentMethod(string $paymentMethodId): ?PaymentHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($paymentMethodId)) {
                return $handler;
            }
        }

        return null;
    }

    public function forProvider(string $providerName): ?PaymentHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if (strcasecmp($handler->getId(), $providerName) === 0) {
                return $handler;
            }
        }

        return null;
    }

    public function isContractFirst(string $paymentMethodId): bool
    {
        return $this->forPaymentMethod($paymentMethodId) !== null;
    }
}
