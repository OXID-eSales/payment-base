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
}
