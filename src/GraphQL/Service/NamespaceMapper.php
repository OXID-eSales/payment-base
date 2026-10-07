<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Service;

/**
 * Tells graphql-base where payment-base's GraphQL controllers and types live.
 * The controller namespace is empty on purpose: the mutations belong to the
 * provider modules (`stripeCheckoutStart`, …); payment-base ships the shared
 * result types.
 *
 * Mirrors `OxidEsales\GraphQL\Base\Framework\NamespaceMapperInterface`
 * WITHOUT implementing it, on purpose: graphql-base is optional for this
 * module, and module activation compiles the container, whose compiler
 * passes reflect (load) the class of every service. A class implementing an
 * interface from an absent package is a fatal "Interface not found" and the
 * module cannot be activated on a shop without GraphQL (seen in CI, Sprint 15
 * / S6). graphql-base only iterates the `graphql_namespace_mapper` tagged
 * services and calls the two methods; it never type-checks them.
 * OptionalGraphQlDependencyTest pins the method parity with the interface.
 *
 * @since 3.0.0
 */
final class NamespaceMapper
{
    /**
     * @return array<string, string>
     */
    public function getControllerNamespaceMapping(): array
    {
        return [
            'OxidEsales\\PaymentBase\\GraphQL\\Controller' => __DIR__ . '/../Controller/',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getTypeNamespaceMapping(): array
    {
        return [
            'OxidEsales\\PaymentBase\\GraphQL\\DataType' => __DIR__ . '/../DataType/',
        ];
    }
}
