<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

use InvalidArgumentException;
use RuntimeException;

/**
 * VR-21 F3 — every refund a provider made is booked on its contract exactly once,
 * keyed by the provider's refund id, whichever path reports it first: the admin
 * action, the provider's webhook, or a refund intent. The admin path and the
 * webhook path reported the same refund separately before, and PayPal and Stripe
 * counted it twice.
 *
 * @since 1.7.0
 */
interface ProviderRefundLedgerInterface
{
    /**
     * @param string $source who reports it: 'admin', 'webhook', 'intent' (for the log)
     * @param bool $succeeded the provider's final word on this refund; false while it is still pending. After
     *                        every call (booked or duplicate) the {@see RefundSettledPolicyInterface} settles the
     *                        contract with it: stock is restored once, on a succeeded refund of the last
     *                        refundable amount
     *
     * @throws InvalidArgumentException empty refund id, non-positive amount, currency other than the contract's
     * @throws RuntimeException no such contract
     */
    public function book(
        string $contractId,
        string $providerRefundId,
        float $amount,
        string $currency,
        string $source,
        bool $succeeded
    ): RefundBooking;
}
