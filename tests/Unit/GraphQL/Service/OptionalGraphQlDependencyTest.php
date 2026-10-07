<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Service;

use OxidEsales\GraphQL\Base\Framework\NamespaceMapperInterface;
use OxidEsales\GraphQL\Base\Framework\PermissionProviderInterface;
use OxidEsales\PaymentBase\GraphQL\Service\NamespaceMapper;
use OxidEsales\PaymentBase\GraphQL\Service\PermissionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * graphql-base is optional. Module activation compiles the container and
 * reflects the class of every service; a class implementing an interface
 * from an absent package is a fatal "Interface not found" (payment-base CI,
 * Sprint 15 / S6). So the GraphQL glue mirrors the graphql-base interfaces
 * without implementing them, and nothing the container reflects may touch
 * graphql-base or GraphQLite.
 */
final class OptionalGraphQlDependencyTest extends TestCase
{
    private const OPTIONAL_NAMESPACES = ['OxidEsales\\GraphQL\\', 'TheCodingMachine\\GraphQLite\\'];

    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function glueAndTheInterfaceItMirrors(): iterable
    {
        yield 'namespace mapper' => [NamespaceMapper::class, NamespaceMapperInterface::class];
        yield 'permission provider' => [PermissionProvider::class, PermissionProviderInterface::class];
    }

    /**
     * Every method of the interface exists on the glue class with the same
     * signature, so graphql-base can call it exactly as it would an
     * implementation. The interface comes from the unit bootstrap's stub or
     * from graphql-base itself, whichever is loaded.
     *
     * @param class-string $glue
     * @param class-string $interface
     */
    #[DataProvider('glueAndTheInterfaceItMirrors')]
    public function testGlueMirrorsTheInterfaceWithoutImplementingIt(string $glue, string $interface): void
    {
        $glueReflection = new \ReflectionClass($glue);
        self::assertNotContains($interface, $glueReflection->getInterfaceNames(), 'must not implement it');

        foreach ((new \ReflectionClass($interface))->getMethods() as $expected) {
            self::assertTrue($glueReflection->hasMethod($expected->getName()), "missing {$expected->getName()}()");
            $actual = $glueReflection->getMethod($expected->getName());
            self::assertTrue($actual->isPublic());
            self::assertSame((string) $expected->getReturnType(), (string) $actual->getReturnType());
            self::assertSame($expected->getNumberOfParameters(), $actual->getNumberOfParameters());
        }
    }

    /**
     * Every class the container will reflect (the `class` key, or the id when
     * it is a class name) must load without graphql-base.
     */
    public function testNoReflectedServiceClassTouchesAnOptionalPackage(): void
    {
        $yaml = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $services = $yaml['services'] ?? [];
        self::assertNotEmpty($services);

        $offenders = [];
        foreach ($services as $id => $definition) {
            $class = $this->reflectedClassOf((string) $id, $definition);
            if ($class === null || !class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $ancestry = $reflection->getInterfaceNames();
            for ($parent = $reflection->getParentClass(); $parent; $parent = $parent->getParentClass()) {
                $ancestry[] = $parent->getName();
            }
            foreach ($ancestry as $ancestor) {
                foreach (self::OPTIONAL_NAMESPACES as $optional) {
                    if (str_starts_with($ancestor, $optional)) {
                        $offenders[] = "$id -> $ancestor";
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'reflected at activation, breaks a shop without graphql-base');
    }

    /**
     * Mirrors Symfony: an explicit `class` wins; a class-looking id is its own class.
     *
     * @param array<string, mixed>|string|null $definition
     */
    private function reflectedClassOf(string $id, array|string|null $definition): ?string
    {
        if (str_starts_with($id, '_') || is_string($definition) || isset($definition['alias'])) {
            return null;
        }
        if (isset($definition['class']) && is_string($definition['class'])) {
            return $definition['class'];
        }

        return str_contains($id, '\\') ? $id : null;
    }
}
