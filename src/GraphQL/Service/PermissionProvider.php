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
 * storefront grants PLACE_ORDER to, and to `oxidanonymous` for the guest
 * checkout (140a): an anonymous token may build a basket with the storefront's
 * own basket rights (the names are mirrored as strings - the storefront is
 * optional for this module) and start a provider checkout. It does not get
 * PLACE_ORDER: core `placeOrder` is refused for contract-first payments anyway
 * and guest orders are not core's to place.
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
     * `paymentClientConfig`: read before any login, with a guest's anonymous
     * token (group `oxidanonymous`), hence granted to that group as well.
     */
    public const PAYMENT_CLIENT_CONFIG = 'PAYMENT_CLIENT_CONFIG';

    /**
     * graphql-storefront's basket rights (Shared\Service\PermissionProvider), the ones that
     * `oxidcustomer` and `oxidnotyetordered` hold apart from PLACE_ORDER.
     */
    private const STOREFRONT_BASKET_RIGHTS = [
        'CREATE_BASKET',
        'VIEW_BASKET',
        'ADD_PRODUCT_TO_BASKET',
        'REMOVE_BASKET_PRODUCT',
        'ADD_VOUCHER',
        'REMOVE_VOUCHER',
    ];

    /**
     * @return array<string, string[]>
     */
    public function getPermissions(): array
    {
        return [
            'oxidadmin' => [self::PAYMENT_CHECKOUT, self::PAYMENT_CLIENT_CONFIG],
            'oxidcustomer' => [self::PAYMENT_CHECKOUT, self::PAYMENT_CLIENT_CONFIG],
            'oxidnotyetordered' => [self::PAYMENT_CHECKOUT, self::PAYMENT_CLIENT_CONFIG],
            'oxidanonymous' => [...self::STOREFRONT_BASKET_RIGHTS, self::PAYMENT_CHECKOUT, self::PAYMENT_CLIENT_CONFIG],
        ];
    }
}
