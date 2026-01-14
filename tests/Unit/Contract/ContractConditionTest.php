<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Contract;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

class ContractConditionTest extends TestCase
{
    public function testConstruct(): void
    {
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $this->assertEquals(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $condition->getType());
        $this->assertEquals(ContractCondition::STATUS_PENDING, $condition->getStatus());
        $this->assertTrue($condition->isPending());
        $this->assertFalse($condition->isFulfilled());
        $this->assertFalse($condition->isFailed());
    }

    public function testInvalidTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid condition type');

        new ContractCondition('invalid_type');
    }

    public function testFulfill(): void
    {
        $condition = new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK);
        $data = ['fraudScore' => 0.1, 'riskLevel' => 'low'];

        $condition->fulfill($data);

        $this->assertTrue($condition->isFulfilled());
        $this->assertFalse($condition->isPending());
        $this->assertEquals($data, $condition->getData());
        $this->assertInstanceOf(\DateTimeInterface::class, $condition->getFulfilledAt());
    }

    public function testFulfillAlreadyFulfilledThrowsException(): void
    {
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $condition->fulfill(['authId' => '123']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('is already fulfilled');

        $condition->fulfill(['authId' => '456']);
    }

    public function testFail(): void
    {
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $condition->fail('Payment declined');

        $this->assertTrue($condition->isFailed());
        $this->assertFalse($condition->isPending());
        $this->assertEquals('Payment declined', $condition->getFailureReason());
    }

    public function testFailFulfilledConditionThrowsException(): void
    {
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $condition->fulfill(['authId' => '123']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot fail a fulfilled condition');

        $condition->fail('Some reason');
    }

    public function testToArray(): void
    {
        $condition = new ContractCondition(ContractCondition::TYPE_STOCK_RESERVED);
        $condition->fulfill(['reservationId' => 'res123']);

        $array = $condition->toArray();

        $this->assertEquals(ContractCondition::TYPE_STOCK_RESERVED, $array['type']);
        $this->assertEquals(ContractCondition::STATUS_FULFILLED, $array['status']);
        $this->assertEquals(['reservationId' => 'res123'], $array['data']);
        $this->assertIsString($array['fulfilledAt']);
        $this->assertNull($array['failureReason']);
    }

    public function testFromArray(): void
    {
        $data = [
            'type' => ContractCondition::TYPE_FRAUD_CHECK,
            'status' => ContractCondition::STATUS_FULFILLED,
            'data' => ['score' => 0.1],
            'fulfilledAt' => '2025-01-01 12:00:00',
            'failureReason' => null,
        ];

        $condition = ContractCondition::fromArray($data);

        $this->assertEquals(ContractCondition::TYPE_FRAUD_CHECK, $condition->getType());
        $this->assertTrue($condition->isFulfilled());
        $this->assertEquals(['score' => 0.1], $condition->getData());
        $this->assertInstanceOf(\DateTimeInterface::class, $condition->getFulfilledAt());
    }

    public function testAllConditionTypes(): void
    {
        $types = [
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            ContractCondition::TYPE_FRAUD_CHECK,
            ContractCondition::TYPE_STOCK_RESERVED,
            ContractCondition::TYPE_COMPLIANCE_CHECK,
            ContractCondition::TYPE_ADDRESS_VALIDATED,
        ];

        foreach ($types as $type) {
            $condition = new ContractCondition($type);
            $this->assertEquals($type, $condition->getType());
        }
    }
}
