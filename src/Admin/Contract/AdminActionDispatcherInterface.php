<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Admin\Contract;

use OxidEsales\Eshop\Application\Model\Order;

/**
 * Sprint I — unified admin-action dispatcher used by PSP panel providers.
 *
 * Each PSP (Stripe, PayPal, …) ships a final dispatcher class (Stripe
 * needs paymentIntentId, PayPal needs nothing extra). To keep their panel
 * providers type-hint-clean without exposing PSP-specific method
 * signatures, both concrete classes implement this common interface. The
 * PSP-specific payload (Stripe's `paymentIntentId`, etc.) travels through
 * the `$extras` array.
 *
 * Keep the verbs simple — `refund`, `capture`, `cancel` — to avoid
 * collision with the PSP-internal `dispatchRefund(…)` etc. methods each
 * final class already exposes and which other callers (admin controllers,
 * event handlers) depend on.
 */
interface AdminActionDispatcherInterface
{
    /**
     * @param array<string, mixed> $extras PSP-specific extras
     *   (Stripe: `paymentIntentId`, `description`; PayPal: none today).
     */
    public function refund(Order $order, ?float $amount, ?string $reason, array $extras = []): void;

    /**
     * @param array<string, mixed> $extras
     */
    public function capture(Order $order, ?float $amount, ?string $reason, array $extras = []): void;

    /**
     * @param array<string, mixed> $extras
     */
    public function cancel(Order $order, ?string $reason, array $extras = []): void;
}
