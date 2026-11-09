<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Eshop;

use OxidEsales\Eshop\Core\Registry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Issue #3 — an override of a core method that has no native return type must not declare one (not even
 * mixed): the first module in the class chain to declare a type closes the chain, and every other module
 * extending the same method must then repeat exactly that type or PHP fatals ("Declaration of ... must be
 * compatible with ..."). The other modules are written against the core signature, as they should be.
 * The type belongs in the docblock ({@inheritDoc} plus @return).
 *
 * Runs against the live shop: the module classes extend `..._parent`, which only exists once OXID built
 * the class chain.
 *
 * @group integration
 */
final class ExtensionSignatureTest extends TestCase
{
    public function testNoOverrideAddsAReturnTypeThatCoreDoesNotDeclare(): void
    {
        $findings = [];
        foreach ($this->extend() as $shopClass => $moduleClass) {
            $this->buildClassChain($shopClass);
            foreach ($this->overridesOfCoreMethods($shopClass, $moduleClass) as [$override, $core]) {
                if ($core->hasReturnType() || !$override->hasReturnType()) {
                    continue;
                }
                $findings[] = sprintf(
                    '%s::%s(): %s — core declares no return type',
                    $moduleClass,
                    $override->getName(),
                    (string) $override->getReturnType()
                );
            }
        }

        self::assertSame(
            [],
            $findings,
            'An override of a core method without a native return type must not declare one; '
            . 'carry the type in the docblock ({@inheritDoc} + @return) instead (issue #3).'
        );
    }

    /** @return array<class-string, class-string> shop class => module class, as metadata.php declares it */
    private function extend(): array
    {
        $aModule = [];
        require dirname(__DIR__, 3) . '/metadata.php';

        return $aModule['extend'] ?? [];
    }

    private function buildClassChain(string $shopClass): void
    {
        try {
            // Resolves the chained class name and, on the way, defines the `..._parent` aliases
            // the module classes extend — without constructing anything.
            Registry::getUtilsObject()->getClassName($shopClass);
        } catch (Throwable $e) {
            self::markTestSkipped('OXID shop unavailable: ' . $e->getMessage());
        }
    }

    /** @return list<array{ReflectionMethod, ReflectionMethod}> [module override, core method] pairs */
    private function overridesOfCoreMethods(string $shopClass, string $moduleClass): array
    {
        $core = new ReflectionClass($this->communityClass($shopClass));
        $pairs = [];
        foreach ((new ReflectionClass($moduleClass))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $moduleClass || !$core->hasMethod($method->getName())) {
                continue;
            }
            $coreMethod = $core->getMethod($method->getName());
            if ($coreMethod->isPrivate()) {
                continue;
            }
            $pairs[] = [$method, $coreMethod];
        }

        return $pairs;
    }

    /** The CE class behind a unified-namespace name: its signature is the one every module is written against. */
    private function communityClass(string $shopClass): string
    {
        return str_replace('OxidEsales\\Eshop\\', 'OxidEsales\\EshopCommunity\\', $shopClass);
    }
}
