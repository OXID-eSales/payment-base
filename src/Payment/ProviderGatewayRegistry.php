<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use LogicException;

/**
 * Collects the gateways tagged `oe.payment.gateway`. One gateway of a kind per provider: a second one is a wiring
 * error and refused when the registry is built, not silently ignored.
 *
 * @since 1.7.0
 */
final class ProviderGatewayRegistry implements ProviderGatewayRegistryInterface
{
    /** @var array<string, CaptureGatewayInterface> */
    private array $captures = [];

    /** @var array<string, RefundGatewayInterface> */
    private array $refunds = [];

    /** @var array<string, CancelGatewayInterface> */
    private array $cancels = [];

    /**
     * @param iterable<ProviderGatewayInterface> $gateways
     */
    public function __construct(iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            $provider = $gateway->providerName();
            $gateway instanceof CaptureGatewayInterface && $this->captures = self::added($this->captures, $provider, $gateway);
            $gateway instanceof RefundGatewayInterface && $this->refunds = self::added($this->refunds, $provider, $gateway);
            $gateway instanceof CancelGatewayInterface && $this->cancels = self::added($this->cancels, $provider, $gateway);
        }
    }

    public function captureFor(string $provider): ?CaptureGatewayInterface
    {
        return $this->captures[$provider] ?? null;
    }

    public function refundFor(string $provider): ?RefundGatewayInterface
    {
        return $this->refunds[$provider] ?? null;
    }

    public function cancelFor(string $provider): ?CancelGatewayInterface
    {
        return $this->cancels[$provider] ?? null;
    }

    /**
     * @template T of ProviderGatewayInterface
     *
     * @param array<string, T> $gateways
     * @param T $gateway
     *
     * @return array<string, T>
     */
    private static function added(array $gateways, string $provider, ProviderGatewayInterface $gateway): array
    {
        if (isset($gateways[$provider])) {
            throw new LogicException(sprintf('Two gateways of the same kind for provider "%s".', $provider));
        }
        $gateways[$provider] = $gateway;

        return $gateways;
    }
}
