<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Response;

/**
 * Normalized payment status constants shared across all payment providers.
 *
 * Sprint 114.10a (A2): Moved from StripeStatusMapper (provider-specific layer)
 * to payment-base so any provider can reference a shared vocabulary without
 * coupling to the Stripe module.
 *
 * String constants (rather than an enum) are used to remain compatible with
 * existing callers that string-compare status values.
 *
 * @since 1.0.0
 */
final class NormalizedPaymentStatus
{
    public const PENDING = 'pending';
    public const AUTHORIZED = 'authorized';
    public const CAPTURED = 'captured';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';

    /** Not instantiable — constants class only. */
    private function __construct()
    {
    }
}
