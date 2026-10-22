<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Relation;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Model\Order as EshopOrder;
use OxidEsales\GraphQL\Storefront\Order\DataType\Order as StorefrontOrder;
use OxidEsales\PaymentBase\Contract\PaymentContractSummary;
use OxidEsales\PaymentBase\GraphQL\DataType\PaymentContractSummaryType;
use OxidEsales\PaymentBase\GraphQL\Relation\OrderRelationService;
use OxidEsales\PaymentBase\Repository\ContractSummaryQueryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;

final class OrderRelationServiceTest extends TestCase
{
    private ContractSummaryQueryInterface&MockObject $summaries;

    protected function setUp(): void
    {
        $this->summaries = $this->createMock(ContractSummaryQueryInterface::class);
    }

    public function testPaymentContract_OrderWithAContract_ReturnsItsSummary(): void
    {
        $updatedAt = new DateTimeImmutable('2026-10-22 10:00:00');
        $this->summaries->method('findByOrderId')->with('order-1')
            ->willReturn(new PaymentContractSummary('contract-1', 'committed', 'stripe', $updatedAt));

        $result = (new OrderRelationService($this->summaries))->paymentContract($this->storefrontOrder('order-1'));

        self::assertInstanceOf(PaymentContractSummaryType::class, $result);
        self::assertSame('committed', $result->state());
        self::assertSame('stripe', $result->provider());
        self::assertSame('contract-1', $result->contractId());
        self::assertSame($updatedAt, $result->updatedAt());
    }

    public function testPaymentContract_OrderWithoutAContract_ReturnsNull(): void
    {
        $this->summaries->method('findByOrderId')->willReturn(null);

        self::assertNull((new OrderRelationService($this->summaries))->paymentContract($this->storefrontOrder('order-twig')));
    }

    public function testClass_IsRegisteredAsAnExtensionOfTheStorefrontOrderType(): void
    {
        $class = new ReflectionClass(OrderRelationService::class);

        $extend = $class->getAttributes(ExtendType::class);
        self::assertCount(1, $extend);
        self::assertSame(['class' => StorefrontOrder::class], $extend[0]->getArguments());
        self::assertCount(1, $class->getMethod('paymentContract')->getAttributes(Field::class));
    }

    private function storefrontOrder(string $orderId): StorefrontOrder
    {
        return new StorefrontOrder(new class ($orderId) extends EshopOrder {
            public function __construct(private readonly string $orderId)
            {
            }

            public function getId(): ?string
            {
                return $this->orderId;
            }
        });
    }
}
