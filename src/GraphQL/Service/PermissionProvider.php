<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Service;

/**
 * The right the providers' checkout mutations demand:
 * `#[Right(PermissionProvider::PAYMENT_CHECKOUT)]`. Granted to the groups the
 * storefront grants PLACE_ORDER to. Anonymous (guest) checkout is phase 4.
 *
 * Mirrors `OxidEsales\GraphQL\Base\Framework\PermissionProviderInterface`
 * WITHOUT implementing it - graphql-base is optional and the container
 * reflects this class at module activation; see NamespaceMapper for the
 * full reasoning. graphql-base's Authorization only iterates the
 * `graphql_permission_provider` tagged services and calls getPermissions().
 *
 * @since 3.0.0
 */
final class PermissionProvider
{
    public const PAYMENT_CHECKOUT = 'PAYMENT_CHECKOUT';

    /**
     * @return array<string, string[]>
     */
    public function getPermissions(): array
    {
        return [
            'oxidadmin' => [self::PAYMENT_CHECKOUT],
            'oxidcustomer' => [self::PAYMENT_CHECKOUT],
            'oxidnotyetordered' => [self::PAYMENT_CHECKOUT],
        ];
    }
}
