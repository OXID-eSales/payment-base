<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Service;

use OxidEsales\GraphQL\Base\Framework\PermissionProviderInterface;

/**
 * The right the providers' checkout mutations demand:
 * `#[Right(PermissionProvider::PAYMENT_CHECKOUT)]`. Granted to the groups the
 * storefront grants PLACE_ORDER to. Anonymous (guest) checkout is phase 4.
 *
 * @since 3.0.0
 */
final class PermissionProvider implements PermissionProviderInterface
{
    public const PAYMENT_CHECKOUT = 'PAYMENT_CHECKOUT';

    public function getPermissions(): array
    {
        return [
            'oxidadmin' => [self::PAYMENT_CHECKOUT],
            'oxidcustomer' => [self::PAYMENT_CHECKOUT],
            'oxidnotyetordered' => [self::PAYMENT_CHECKOUT],
        ];
    }
}
