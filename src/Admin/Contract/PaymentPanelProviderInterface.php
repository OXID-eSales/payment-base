<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Admin\Contract;

use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelRenderable;

/**
 * Implemented by PSP modules to inject a panel into payment-component's
 * shared "Payment" admin tab.
 *
 * Providers register under the `oe.payment.admin_panel` tag; the
 * {@see PaymentPanelRegistryInterface} iterates them and picks the first
 * one whose {@see self::supports()} returns true for the active order.
 *
 * Keep implementations thin: no OXID admin inheritance, no templates
 * beyond a body fragment, no tab/menu registration. The shared wrapper
 * template owns the admin frame; providers only emit the PSP-specific
 * body + helpers.
 */
interface PaymentPanelProviderInterface
{
    /**
     * Short stable key — e.g. `stripe`, `paypal`. Matches
     * `PaymentContract::getProvider()`. Stamped as `data-provider` on the
     * rendered panel wrapper.
     */
    public function getProviderName(): string;

    /**
     * True when this provider wants to render the panel for the given
     * context. Usually a payment-type or contract-provider check.
     */
    public function supports(PaymentPanelContext $context): bool;

    /**
     * Build the panel's template + view data. Always returns a
     * renderable — never null, never a sentinel. Negative cases live
     * in {@see self::supports()}.
     */
    public function build(PaymentPanelContext $context): PaymentPanelRenderable;

    /**
     * Handle an admin-form action (refund, capture, cancel, …). The
     * controller validates CSRF before calling. Providers translate
     * into their PSP-specific events and dispatch through the broker.
     *
     * @param array<string, mixed> $request Parsed request params relevant to the action.
     */
    public function handleAction(string $action, array $request, PaymentPanelContext $context): void;
}
