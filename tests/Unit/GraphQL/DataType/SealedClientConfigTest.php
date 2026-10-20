<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\DataType;

use OxidEsales\PaymentBase\GraphQL\DataType\SealedClientConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * GraphQLite publishes a #[Field] method under its name minus a leading "get"
 * or "is" — `issuedAt()` became the field `suedAt`, and the browser asking for
 * `issuedAt` got a validation error (Sprint 137, found by the deployed-schema
 * spec). Such methods must name their field explicitly.
 */
final class SealedClientConfigTest extends TestCase
{
    private const FIELD_ATTRIBUTE = 'TheCodingMachine\GraphQLite\Annotations\Field';

    public function testFieldsWhoseMethodNameStartsWithIsOrGetDeclareTheirGraphQlName(): void
    {
        foreach ($this->fieldMethods() as $method) {
            if (!preg_match('/^(is|get)./', $method->getName())) {
                continue;
            }

            self::assertSame(
                $method->getName(),
                $this->declaredFieldName($method),
                sprintf('GraphQLite would publish %s() under a stripped name', $method->getName())
            );
        }
    }

    public function testIssuedAtIsPublishedAsIssuedAt(): void
    {
        $method = new ReflectionMethod(SealedClientConfig::class, 'issuedAt');

        self::assertSame('issuedAt', $this->declaredFieldName($method));
    }

    /**
     * @return list<ReflectionMethod>
     */
    private function fieldMethods(): array
    {
        $methods = (new ReflectionClass(SealedClientConfig::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        return array_values(array_filter(
            $methods,
            static fn (ReflectionMethod $method): bool => $method->getAttributes(self::FIELD_ATTRIBUTE) !== []
        ));
    }

    private function declaredFieldName(ReflectionMethod $method): ?string
    {
        $attributes = $method->getAttributes(self::FIELD_ATTRIBUTE);
        if ($attributes === []) {
            return null;
        }
        $name = $attributes[0]->getArguments()['name'] ?? null;

        return is_string($name) ? $name : null;
    }
}
