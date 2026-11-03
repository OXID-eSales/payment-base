<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

/**
 * The only thing payment-base needs to ask a provider about a payment nobody is waiting for any more: what
 * it holds, and to give it back. One implementation per provider, tagged `oe.payment.provider_payment_gateway`.
 *
 * Narrow on purpose: it reads and returns whole payments by the provider's own id. Partial admin refunds and
 * captures are the money operations of sprint 22, not of this port.
 *
 * @since 1.8.0
 */
interface ProviderPaymentGatewayInterface
{
    /** The provider name contracts carry (PaymentContractInterface::getProvider()). */
    public function provider(): string;

    /**
     * Asked at the provider, never answered from a cache: it decides between refunding and releasing.
     */
    public function statusOf(string $providerPaymentId): ProviderPaymentStatus;

    /**
     * Refunds the whole captured payment.
     *
     * @param string $idempotencyKey sent to the provider; a repeated key must not refund twice
     *
     * @return string the provider's refund id
     */
    public function refund(string $providerPaymentId, string $idempotencyKey): string;

    /**
     * Releases (voids) the authorization.
     *
     * @param string $idempotencyKey sent to the provider where it takes one
     */
    public function release(string $providerPaymentId, string $idempotencyKey): void;
}
