<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Service;

use OxidEsales\PaymentBase\GraphQL\Service\NamespaceMapper;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S6 — graphql-base discovers a module's GraphQL controllers and
 * types through its namespace mapper (tag `graphql_namespace_mapper`).
 */
final class NamespaceMapperTest extends TestCase
{
    public function testMapsTheGraphQlControllerAndTypeNamespacesToExistingDirectories(): void
    {
        $mapper = new NamespaceMapper();

        $controllers = $mapper->getControllerNamespaceMapping();
        $types = $mapper->getTypeNamespaceMapping();

        self::assertArrayHasKey('OxidEsales\\PaymentBase\\GraphQL\\Controller', $controllers);
        self::assertArrayHasKey('OxidEsales\\PaymentBase\\GraphQL\\DataType', $types);
        self::assertDirectoryExists($controllers['OxidEsales\\PaymentBase\\GraphQL\\Controller']);
        self::assertDirectoryExists($types['OxidEsales\\PaymentBase\\GraphQL\\DataType']);
    }

    public function testGetTypeNamespaceMapping_StorefrontInstalled_MapsTheOrderRelationNamespace(): void
    {
        $types = (new NamespaceMapper(storefrontInstalled: true))->getTypeNamespaceMapping();

        self::assertArrayHasKey('OxidEsales\\PaymentBase\\GraphQL\\Relation', $types);
        self::assertDirectoryExists($types['OxidEsales\\PaymentBase\\GraphQL\\Relation']);
    }

    /**
     * The extension names the storefront's Order type; mapping it without the
     * storefront would break the whole schema, not just this field.
     */
    public function testGetTypeNamespaceMapping_StorefrontAbsent_LeavesTheOrderRelationOut(): void
    {
        $types = (new NamespaceMapper(storefrontInstalled: false))->getTypeNamespaceMapping();

        self::assertArrayNotHasKey('OxidEsales\\PaymentBase\\GraphQL\\Relation', $types);
        self::assertArrayHasKey('OxidEsales\\PaymentBase\\GraphQL\\DataType', $types);
    }

    public function testAdminApiInstalled_MapsTheAdminPaymentControllersTypesAndOrderRelation(): void
    {
        $mapper = new NamespaceMapper(storefrontInstalled: false, adminApiInstalled: true);

        $controllers = $mapper->getControllerNamespaceMapping();
        $types = $mapper->getTypeNamespaceMapping();

        self::assertDirectoryExists($controllers['OxidEsales\\PaymentBase\\GraphQL\\Admin\\Controller']);
        self::assertDirectoryExists($types['OxidEsales\\PaymentBase\\GraphQL\\Admin\\DataType']);
        self::assertDirectoryExists($types['OxidEsales\\PaymentBase\\GraphQL\\Admin\\Relation']);
    }

    /**
     * The admin types name classes of oxid-admin-graphql (its error interface, AdminOrder): mapped without it,
     * the schema of a shop without the admin API would fail to build.
     */
    public function testAdminApiAbsent_MapsNothingOfTheAdminPaymentApi(): void
    {
        $mapper = new NamespaceMapper(storefrontInstalled: true, adminApiInstalled: false);

        self::assertArrayNotHasKey('OxidEsales\\PaymentBase\\GraphQL\\Admin\\Controller', $mapper->getControllerNamespaceMapping());
        foreach (array_keys($mapper->getTypeNamespaceMapping()) as $namespace) {
            self::assertStringNotContainsString('GraphQL\\Admin\\', $namespace . '\\');
        }
    }
}
