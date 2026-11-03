<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

/**
 * Finds the provider's gateway for an operation. Null: the provider module has none, and the admin is told no
 * provider handles the payment.
 *
 * @since 1.7.0
 */
interface ProviderGatewayRegistryInterface
{
    public function captureFor(string $provider): ?CaptureGatewayInterface;

    public function refundFor(string $provider): ?RefundGatewayInterface;

    public function cancelFor(string $provider): ?CancelGatewayInterface;
}
