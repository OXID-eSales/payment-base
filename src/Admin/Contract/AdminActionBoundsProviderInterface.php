<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Contract;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Optional second interface of a {@see PaymentPanelProviderInterface}: the provider's own, live capture and refund
 * limits for a contract. They replace payment-base's local numbers in the admin API (what `amountCapturable` /
 * `amountRefundable` offer and what the mutations enforce), so the headless screen offers what the provider's own
 * panel offers. Return null for "no opinion"; throw (or return {@see AdminActionBounds::unavailable()}) when the
 * limit cannot be read: the admin API then offers nothing and refuses a write with `provider_unavailable`.
 * Called lazily and at most once per read. Outside the GraphQL namespaces on purpose.
 *
 * @since 1.7.0
 */
interface AdminActionBoundsProviderInterface
{
    public function boundsFor(PaymentContractInterface $contract): ?AdminActionBounds;
}
