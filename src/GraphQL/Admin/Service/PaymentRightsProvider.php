<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\GraphQL\Admin\Authorization\DataType\RightDefinition;

/**
 * The admin rights of the payment operations, added to the catalogue of oxid-admin-graphql (G04).
 * Tagged `graphql_admin_rights_provider`; the admin API's operations carry them as `#[Right(...)]`.
 *
 * Mirrors `OxidEsales\GraphQL\Admin\Authorization\Service\RightsProviderInterface` WITHOUT implementing
 * it: the admin module is optional for payment-base and the container reflects the class of every service
 * at module activation (see NamespaceMapper). The catalogue only iterates the tagged services and calls
 * rights(). `RightDefinition` is referenced inside the method, so it loads only when the catalogue asks.
 *
 * CONFIG is defined here for the read-only provider configuration queries of the admin (the provider plugins use it);
 * no operation of payment-base itself demands it. Settings change in the classic admin only (CFG-1).
 *
 * @since 1.5.0
 */
final class PaymentRightsProvider
{
    public const VIEW = 'ADMIN_PAYMENT_VIEW';
    public const CAPTURE = 'ADMIN_PAYMENT_CAPTURE';
    public const REFUND = 'ADMIN_PAYMENT_REFUND';
    public const CONFIG = 'ADMIN_PAYMENT_CONFIG';

    private const DOMAIN = 'payment';

    /**
     * @return list<RightDefinition>
     */
    public function rights(): array
    {
        return [
            RightDefinition::of(self::VIEW, self::DOMAIN, 'View the payment contract and transactions of an order'),
            RightDefinition::of(self::CAPTURE, self::DOMAIN, 'Capture or void an authorised payment'),
            RightDefinition::of(self::REFUND, self::DOMAIN, 'Refund a captured payment'),
            RightDefinition::of(self::CONFIG, self::DOMAIN, 'See the configuration of a payment provider (read-only)'),
        ];
    }
}
