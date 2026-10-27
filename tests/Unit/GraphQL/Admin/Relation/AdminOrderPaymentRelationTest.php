<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Relation;

use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrder;
use OxidEsales\PaymentBase\GraphQL\Admin\Relation\AdminOrderPaymentRelation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class AdminOrderPaymentRelationTest extends TestCase
{
    public function testPaymentContract_OrderWithAContract_ReturnsItsPaymentState(): void
    {
        $contracts = new InMemoryContracts();
        $contracts->save(ContractFactory::fulfilled('order-1', 'mollie', 50.0, 50.0, 5.0));

        $contract = $this->relation($contracts)->paymentContract(new AdminOrder('order-1'));

        self::assertNotNull($contract);
        self::assertSame('mollie', $contract->provider());
        self::assertSame('FULFILLED', $contract->state());
        self::assertSame(45.0, $contract->amountRefundable());
    }

    public function testPaymentContract_OrderWithoutAContract_ReturnsNull(): void
    {
        self::assertNull($this->relation(new InMemoryContracts())->paymentContract(new AdminOrder('order-invoice')));
    }

    public function testExtendsAdminOrder_WithAFieldThatNeedsTheViewRight(): void
    {
        $class = new \ReflectionClass(AdminOrderPaymentRelation::class);
        $extend = $class->getAttributes(ExtendType::class);
        $method = new ReflectionMethod(AdminOrderPaymentRelation::class, 'paymentContract');

        self::assertCount(1, $extend);
        self::assertCount(1, $method->getAttributes(Field::class));
        self::assertCount(1, $method->getAttributes(Right::class));
        self::assertSame([PaymentRightsProvider::VIEW], $method->getAttributes(Right::class)[0]->getArguments());
    }

    private function relation(InMemoryContracts $contracts): AdminOrderPaymentRelation
    {
        return new AdminOrderPaymentRelation(
            new PaymentContractDetailsReader($contracts, $this->createStub(TransactionRepositoryInterface::class)),
        );
    }
}
