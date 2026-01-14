<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Repository;

use OxidEsales\PaymentComponent\Repository\PaymentCustomerRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * TDD Tests for PaymentCustomerRepositoryInterface
 *
 * Sprint 2 Phase 2: Provider-agnostic customer repository interface
 * to replace Stripe-specific osc_stripe_customer_mapping table.
 *
 * LSP Compliance: Interface must be substitutable by any implementation.
 *
 * @group sprint-2
 * @group customer-consolidation
 */
class PaymentCustomerRepositoryInterfaceTest extends TestCase
{
    /**
     * @test
     * RED: Interface should exist
     */
    public function interfaceShouldExist(): void
    {
        $this->assertTrue(
            interface_exists(PaymentCustomerRepositoryInterface::class),
            'PaymentCustomerRepositoryInterface should exist'
        );
    }

    /**
     * @test
     * RED: Interface should have findByUserId method
     */
    public function interfaceShouldHaveFindByUserIdMethod(): void
    {
        $reflection = new ReflectionClass(PaymentCustomerRepositoryInterface::class);

        $this->assertTrue(
            $reflection->hasMethod('findByUserId'),
            'Interface should have findByUserId method'
        );

        $method = $reflection->getMethod('findByUserId');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('userId', $params[0]->getName());
    }

    /**
     * @test
     * RED: Interface should have save method
     */
    public function interfaceShouldHaveSaveMethod(): void
    {
        $reflection = new ReflectionClass(PaymentCustomerRepositoryInterface::class);

        $this->assertTrue(
            $reflection->hasMethod('save'),
            'Interface should have save method'
        );
    }

    /**
     * @test
     * RED: Interface should have findPaymentCustomerId method
     */
    public function interfaceShouldHaveFindPaymentCustomerIdMethod(): void
    {
        $reflection = new ReflectionClass(PaymentCustomerRepositoryInterface::class);

        $this->assertTrue(
            $reflection->hasMethod('findPaymentCustomerId'),
            'Interface should have findPaymentCustomerId method'
        );
    }

    /**
     * @test
     * RED: Interface should have savePaymentCustomerId method
     */
    public function interfaceShouldHaveSavePaymentCustomerIdMethod(): void
    {
        $reflection = new ReflectionClass(PaymentCustomerRepositoryInterface::class);

        $this->assertTrue(
            $reflection->hasMethod('savePaymentCustomerId'),
            'Interface should have savePaymentCustomerId method'
        );
    }
}
