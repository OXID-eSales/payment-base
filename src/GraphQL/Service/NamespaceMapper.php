<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Service;

/**
 * Tells graphql-base where payment-base's GraphQL controllers and types live.
 * The checkout mutations belong to the provider modules (`stripeCheckoutStart`,
 * …); payment-base ships the shared result types and the one provider-neutral
 * query, `paymentClientConfig`.
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
    private const STOREFRONT_ORDER_TYPE = 'OxidEsales\\GraphQL\\Storefront\\Order\\DataType\\Order';
    private const ADMIN_ORDER_TYPE = 'OxidEsales\\GraphQL\\Admin\\Order\\DataType\\AdminOrder';

    /**
     * @param bool|null $storefrontInstalled null: ask the autoloader whether graphql-storefront is there
     * @param bool|null $adminApiInstalled   null: ask the autoloader whether oxid-admin-graphql is there
     */
    public function __construct(
        private readonly ?bool $storefrontInstalled = null,
        private readonly ?bool $adminApiInstalled = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getControllerNamespaceMapping(): array
    {
        $mapping = [
            'OxidEsales\\PaymentBase\\GraphQL\\Controller' => __DIR__ . '/../Controller/',
        ];
        // The admin payment mutations name types of the admin API: mapped only when it is installed.
        if ($this->adminApiAvailable()) {
            $mapping['OxidEsales\\PaymentBase\\GraphQL\\Admin\\Controller'] = __DIR__ . '/../Admin/Controller/';
        }

        return $mapping;
    }

    /**
     * @return array<string, string>
     */
    public function getTypeNamespaceMapping(): array
    {
        $mapping = [
            'OxidEsales\\PaymentBase\\GraphQL\\DataType' => __DIR__ . '/../DataType/',
        ];

        // The Order extension names the storefront's Order type: mapped only
        // when that type exists, or the schema of a shop without the
        // storefront would fail to build.
        if ($this->storefrontInstalled ?? class_exists(self::STOREFRONT_ORDER_TYPE)) {
            $mapping['OxidEsales\\PaymentBase\\GraphQL\\Relation'] = __DIR__ . '/../Relation/';
        }

        // Same reasoning for the admin API: its error interface and AdminOrder are what these types are made of.
        if ($this->adminApiAvailable()) {
            $mapping['OxidEsales\\PaymentBase\\GraphQL\\Admin\\DataType'] = __DIR__ . '/../Admin/DataType/';
            $mapping['OxidEsales\\PaymentBase\\GraphQL\\Admin\\Relation'] = __DIR__ . '/../Admin/Relation/';
        }

        return $mapping;
    }

    private function adminApiAvailable(): bool
    {
        return $this->adminApiInstalled ?? class_exists(self::ADMIN_ORDER_TYPE);
    }
}
