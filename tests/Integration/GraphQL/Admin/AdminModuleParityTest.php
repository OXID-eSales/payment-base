<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL\Admin;

use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\GraphQL\Admin\Authorization\DataType\RightDefinition;
use OxidEsales\GraphQL\Admin\Authorization\Service\RightsProviderInterface;
use OxidEsales\GraphQL\Admin\Checkout\DataType\AdminPaymentOwnership;
use OxidEsales\GraphQL\Admin\Checkout\DataType\PaymentClaim;
use OxidEsales\GraphQL\Admin\AdminSession\DataType\AdminSession;
use OxidEsales\GraphQL\Admin\Mfa\Service\StepUp;
use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrder;
use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderCancelled;
use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderChangeGuard;
use OxidEsales\GraphQL\Admin\Order\DataType\OrderChangeKind;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\NotFoundError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * payment-base's unit suite runs without oxid-admin-graphql, against stand-ins of its classes (the bootstrap).
 * This pins, against the REAL classes, every member payment-base relies on, so a stand-in cannot drift from the
 * thing it stands for: when the admin module changes one of these, this test says so before a shop does.
 */
final class AdminModuleParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(RightDefinition::class)) {
            self::markTestSkipped('oxid-admin-graphql is not installed');
        }
    }

    /**
     * @return iterable<string, array{class-string, string, list<string>, string}>
     */
    public static function membersPaymentBaseCalls(): iterable
    {
        yield 'RightDefinition::of' => [RightDefinition::class, 'of', ['string', 'string', 'string'], 'self'];
        yield 'RightDefinition::right' => [RightDefinition::class, 'right', [], 'string'];
        yield 'RightsProviderInterface::rights' => [RightsProviderInterface::class, 'rights', [], 'array'];
        yield 'AdminOrderChangeGuard::refuse' => [AdminOrderChangeGuard::class, 'refuse', ['string'], 'void'];
        yield 'AdminOrderChangeGuard::isRefused' => [AdminOrderChangeGuard::class, 'isRefused', [], 'bool'];
        yield 'AdminOrderChangeGuard::reason' => [AdminOrderChangeGuard::class, 'reason', [], 'string'];
        yield 'AdminPaymentOwnership::claim' => [AdminPaymentOwnership::class, 'claim', ['string', '?array'], 'void'];
        yield 'AdminPaymentOwnership::claimed' => [AdminPaymentOwnership::class, 'claimed', [], '?' . PaymentClaim::class];
        yield 'StepUp::require' => [StepUp::class, 'require', ['int'], AdminSession::class];
        yield 'AuditRecorder::auditedAdmin' => [AuditRecorder::class, 'auditedAdmin', ['string', \Closure::class], 'mixed'];
        yield 'AuditTrail::on' => [AuditTrail::class, 'on', ['string', 'string'], 'self'];
        yield 'AuditTrail::field' => [AuditTrail::class, 'field', ['string'], 'self'];
        yield 'AuditTrail::value' => [AuditTrail::class, 'value', ['string', 'array|string|int|float|bool'], 'self'];
        yield 'PayloadWithErrors::errors' => [PayloadWithErrors::class, 'errors', [], 'array'];
        yield 'AdminError::errorCode' => [AdminError::class, 'errorCode', [], 'string'];
        yield 'AdminError::message' => [AdminError::class, 'message', [], 'string'];
        yield 'ValidationError::field' => [ValidationError::class, 'field', [], 'string'];
        yield 'ValidationError::code' => [ValidationError::class, 'code', [], 'string'];
    }

    /**
     * @param class-string $class
     * @param list<string> $parameterTypes
     */
    #[DataProvider('membersPaymentBaseCalls')]
    public function testTheRealClassHasTheMemberWithTheSignaturePaymentBaseCalls(string $class, string $method, array $parameterTypes, string $returnType): void
    {
        $member = new ReflectionMethod($class, $method);

        self::assertTrue($member->isPublic());
        self::assertSame($parameterTypes, array_map(
            static fn (\ReflectionParameter $parameter): string => self::typeName($parameter->getType()),
            array_slice($member->getParameters(), 0, count($parameterTypes)),
        ));
        self::assertSame($returnType, self::typeName($member->getReturnType(), $class));
    }

    public function testConstructorsPaymentBaseCalls(): void
    {
        self::assertSame(['string', 'int', 'string', 'string', OrderChangeKind::class], self::constructorTypes(AdminOrderChangeGuard::class));
        self::assertSame(['string', 'int', 'string', 'string'], self::constructorTypes(AdminOrderCancelled::class));
        self::assertSame(['string'], self::constructorTypes(AdminPaymentOwnership::class));
        self::assertSame(['?string'], self::constructorTypes(NotFoundError::class));
        self::assertSame(['string', 'string', '?string'], self::constructorTypes(ValidationError::class));
        self::assertSame(['string'], self::constructorTypes(AuditTrail::class));
    }

    public function testTheFactsPaymentBaseReadsOff_AdminOrderAndTheRest(): void
    {
        $id = new \ReflectionProperty(AdminOrder::class, 'id');
        self::assertTrue($id->isPublic() && $id->isReadOnly());
        self::assertSame('string', (string) $id->getType());

        self::assertSame(300, StepUp::FIVE_MINUTES);
        self::assertSame(['title', 'description'], PaymentClaim::DEFAULT_READ_ONLY);
        self::assertTrue((new ReflectionClass(OrderChangeKind::class))->isEnum());
        foreach (['Items', 'Addresses', 'Payment', 'DeliverySet'] as $case) {
            self::assertNotNull(constant(OrderChangeKind::class . '::' . $case));
        }
        self::assertSame('validation_failed', (new ValidationError('f', 'c'))->errorCode());
        self::assertSame('not_found', (new NotFoundError())->errorCode());
    }

    /**
     * @return list<string>
     */
    private static function constructorTypes(string $class): array
    {
        return array_map(
            static fn (\ReflectionParameter $parameter): string => self::typeName($parameter->getType()),
            (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        );
    }

    private static function typeName(?\ReflectionType $type, ?string $owner = null): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $prefix = $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '';

            return $prefix . ($name === 'static' ? 'self' : $name);
        }

        return (string) $type;
    }
}
