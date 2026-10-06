<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Service;

use OxidEsales\GraphQL\Base\Framework\NamespaceMapperInterface;

/**
 * Tells graphql-base where payment-base's GraphQL controllers and types live.
 * The controller namespace is empty on purpose: the mutations belong to the
 * provider modules (`stripeCheckoutStart`, …); payment-base ships the shared
 * result types.
 *
 * Only loaded when graphql-base is installed (the service is neither
 * autowired nor autoconfigured, and its tag is consumed by graphql-base).
 *
 * @since 3.0.0
 */
final class NamespaceMapper implements NamespaceMapperInterface
{
    public function getControllerNamespaceMapping(): array
    {
        return [
            'OxidEsales\\PaymentBase\\GraphQL\\Controller' => __DIR__ . '/../Controller/',
        ];
    }

    public function getTypeNamespaceMapping(): array
    {
        return [
            'OxidEsales\\PaymentBase\\GraphQL\\DataType' => __DIR__ . '/../DataType/',
        ];
    }
}
