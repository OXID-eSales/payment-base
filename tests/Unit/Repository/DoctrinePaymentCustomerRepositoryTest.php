<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentComponent\Repository\DoctrinePaymentCustomerRepository;
use OxidEsales\PaymentComponent\Repository\PaymentCustomerRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for DoctrinePaymentCustomerRepository
 *
 * Sprint 2 Phase 2: Repository must use osc_payment_customer table
 * instead of osc_stripe_customer_mapping.
 *
 * @group sprint-2
 * @group customer-consolidation
 */
class DoctrinePaymentCustomerRepositoryTest extends TestCase
{
    /**
     * @test
     * RED: Repository should implement PaymentCustomerRepositoryInterface
     */
    public function repositoryShouldImplementInterface(): void
    {
        $connection = $this->createMock(Connection::class);
        $repository = new DoctrinePaymentCustomerRepository($connection);

        $this->assertInstanceOf(PaymentCustomerRepositoryInterface::class, $repository);
    }

    /**
     * @test
     * RED: Repository should use osc_payment_customer table
     */
    public function repositoryShouldUsePaymentCustomerTable(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('osc_payment_customer'),
                $this->anything()
            )
            ->willReturn(null);

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $repository->findByUserId('user_123');
    }

    /**
     * @test
     * RED: Repository should NOT use osc_stripe_customer_mapping table
     */
    public function repositoryShouldNotUseStripeTable(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->logicalNot($this->stringContains('osc_stripe_customer_mapping')),
                $this->anything()
            )
            ->willReturn(null);

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $repository->findByUserId('user_456');
    }

    /**
     * @test
     * RED: findPaymentCustomerId should return customer ID
     */
    public function findPaymentCustomerIdShouldReturnCustomerId(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn('cus_stripe_123');

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $result = $repository->findPaymentCustomerId('user_789');

        $this->assertEquals('cus_stripe_123', $result);
    }

    /**
     * @test
     * RED: findPaymentCustomerId should return null for non-existent user
     */
    public function findPaymentCustomerIdShouldReturnNullForNonExistentUser(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(false);

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $result = $repository->findPaymentCustomerId('nonexistent_user');

        $this->assertNull($result);
    }

    /**
     * @test
     * RED: savePaymentCustomerId should insert new record
     */
    public function savePaymentCustomerIdShouldInsertNewRecord(): void
    {
        $connection = $this->createMock(Connection::class);

        // First check if exists
        $connection
            ->method('fetchOne')
            ->willReturn(false);

        // Then insert
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'osc_payment_customer',
                $this->callback(function (array $data) {
                    return isset($data['OXUSERID'])
                        && $data['OXUSERID'] === 'user_new'
                        && isset($data['OXPAYMENTCUSTOMERID'])
                        && $data['OXPAYMENTCUSTOMERID'] === 'cus_new_123';
                })
            );

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $repository->savePaymentCustomerId('user_new', 'cus_new_123');
    }

    /**
     * @test
     * RED: savePaymentCustomerId should update existing record
     */
    public function savePaymentCustomerIdShouldUpdateExistingRecord(): void
    {
        $connection = $this->createMock(Connection::class);

        // Exists check returns true
        $connection
            ->method('fetchOne')
            ->willReturn('existing_id');

        // Then update
        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'osc_payment_customer',
                $this->callback(function (array $data) {
                    return isset($data['OXPAYMENTCUSTOMERID'])
                        && $data['OXPAYMENTCUSTOMERID'] === 'cus_updated_456';
                }),
                ['OXUSERID' => 'user_existing']
            );

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $repository->savePaymentCustomerId('user_existing', 'cus_updated_456');
    }

    /**
     * @test
     * RED: Repository should use OXPAYMENTCUSTOMERID column (not OXSTRIPECUSTOMERID)
     */
    public function repositoryShouldUseProviderAgnosticColumn(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql) {
                    return strpos($sql, 'OXPAYMENTCUSTOMERID') !== false
                        && strpos($sql, 'OXSTRIPECUSTOMERID') === false;
                }),
                $this->anything()
            )
            ->willReturn('cus_123');

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $repository->findPaymentCustomerId('user_test');
    }
}
