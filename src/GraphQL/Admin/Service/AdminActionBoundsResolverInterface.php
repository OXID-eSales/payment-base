<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * @since 1.7.0
 */
interface AdminActionBoundsResolverInterface
{
    /** The contract's provider's bounds, lazily; null when the provider declares none. */
    public function boundsFor(PaymentContractInterface $contract): ?LazyActionBounds;
}
