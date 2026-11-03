<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Fixture;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminActionBoundsResolverInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\LazyActionBounds;

/** A provider that declares no bounds. */
final class NoActionBounds implements AdminActionBoundsResolverInterface
{
    public function boundsFor(PaymentContractInterface $contract): ?LazyActionBounds
    {
        return null;
    }
}
