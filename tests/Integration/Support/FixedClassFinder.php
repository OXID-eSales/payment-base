<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Support;

use ArrayIterator;
use Iterator;
use Kcs\ClassFinder\Finder\FinderInterface;
use ReflectionClass;

/**
 * A class finder over a fixed list. GraphQLite's default (composer classmap)
 * finder reflects every class under a namespace prefix, which in the shop
 * re-enters OXID's module-chain autoloader for other modules' controllers; a
 * schema under test needs exactly the classes it names. Only loaded by tests
 * that first check GraphQLite is installed: a named class implementing
 * kcs/class-finder's interface is a fatal "Interface not found" without it.
 */
final class FixedClassFinder implements FinderInterface
{
    /** @var list<string> */
    private array $namespaces = [];

    /** @param list<class-string> $classes */
    public function __construct(private readonly array $classes)
    {
    }

    public function getIterator(): Iterator
    {
        $found = [];
        foreach ($this->classes as $class) {
            foreach ($this->namespaces ?: [''] as $namespace) {
                if (str_starts_with($class, $namespace)) {
                    $found[$class] = new ReflectionClass($class);
                    break;
                }
            }
        }

        return new ArrayIterator($found);
    }

    public function inNamespace(string|array $namespaces): self
    {
        $clone = clone $this;
        $clone->namespaces = array_map(
            static fn(string $ns): string => rtrim($ns, '\\') . '\\',
            (array) $namespaces
        );

        return $clone;
    }

    public function implementationOf(string|array $interface): self
    {
        return $this;
    }

    public function subclassOf(string|null $superClass): self
    {
        return $this;
    }

    public function annotatedBy(string|null $annotationClass): self
    {
        return $this;
    }

    public function withAttribute(string|null $attributeClass): self
    {
        return $this;
    }

    public function in(string|array $dirs): self
    {
        return $this;
    }

    public function notInNamespace(string|array $namespaces): self
    {
        return $this;
    }

    public function filter(callable|null $callback): self
    {
        return $this;
    }

    public function path(string $pattern): self
    {
        return $this;
    }

    public function notPath(string $pattern): self
    {
        return $this;
    }

    public function pathFilter(callable|null $callback): self
    {
        return $this;
    }
}
