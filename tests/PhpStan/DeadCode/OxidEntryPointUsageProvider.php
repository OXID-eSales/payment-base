<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\PhpStan\DeadCode;

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Entry points of the dead-code gate (sprint 17 DC-5) that are called by a framework, not by PHP code.
 *
 * Three callers are invisible to a static call graph:
 * - GraphQLite builds the schema from attributes (`#[Field]`, `#[Query]`, `#[Mutation]`, ...);
 * - the shop calls the `events` of `metadata.php` by name;
 * - the shop's controller/model extend chain (`src/Eshop/`) and the admin controllers are called by
 *   templates and by `fnc=` request parameters.
 */
final class OxidEntryPointUsageProvider extends ReflectionBasedMemberUsageProvider
{
    private const GRAPHQLITE_ATTRIBUTE_NAMESPACE = 'TheCodingMachine\\GraphQLite\\Annotations\\';

    /** `events` of metadata.php: class => handler methods. */
    private const METADATA_EVENT_HANDLERS = [
        'OxidEsales\\PaymentBase\\Core\\Events\\ModuleLifecycle' => ['onActivate', 'onDeactivate'],
    ];

    private const SHOP_EXTEND_CHAIN_MARKER = '\\Eshop\\';
    private const ADMIN_MARKER = '\\Admin\\';

    public function isEntryPoint(ReflectionMethod $method): bool
    {
        if (!$method->isPublic()) {
            return false;
        }

        return $this->hasGraphQliteAttribute($method)
            || $this->isMetadataEventHandler($method)
            || $this->isCalledByTemplatesOrRequests($method);
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->isEntryPoint($method)) {
            return null;
        }

        return VirtualUsageData::withNote('Called by GraphQLite, metadata.php events, a template or fnc=');
    }

    private function hasGraphQliteAttribute(ReflectionMethod $method): bool
    {
        foreach ($method->getAttributes() as $attribute) {
            if (str_starts_with($attribute->getName(), self::GRAPHQLITE_ATTRIBUTE_NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    private function isMetadataEventHandler(ReflectionMethod $method): bool
    {
        $handlers = self::METADATA_EVENT_HANDLERS[$method->getDeclaringClass()->getName()] ?? [];

        return in_array($method->getName(), $handlers, true);
    }

    private function isCalledByTemplatesOrRequests(ReflectionMethod $method): bool
    {
        $className = $method->getDeclaringClass()->getName();
        if (str_contains($className, self::SHOP_EXTEND_CHAIN_MARKER)) {
            return true;
        }

        return str_contains($className, self::ADMIN_MARKER) && str_ends_with($className, 'Controller');
    }
}
