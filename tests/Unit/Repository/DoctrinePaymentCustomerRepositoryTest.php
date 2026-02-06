<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\PaymentComponent\Contract\PaymentCustomer;
use OxidEsales\PaymentComponent\Repository\DoctrinePaymentCustomerRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DoctrinePaymentCustomerRepository.
 *
 * Sprint 45: Stripe Customer lifecycle.
 *
 * @covers \OxidEsales\PaymentComponent\Repository\DoctrinePaymentCustomerRepository
 * @group sprint-45
 */
class DoctrinePaymentCustomerRepositoryTest extends TestCase
{
    /**
     * @test
     */
    public function saveInsertsNewRecord(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(0);

        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'oe_payments_customer',
                $this->callback(function (array $data) {
                    return $data['OXID'] === 'id_123'
                        && $data['OXUSERID'] === 'user_abc'
                        && $data['OXPAYMENTCUSTOMERID'] === 'cus_stripe_xyz'
                        && $data['OXBILLINGAGREEMENT'] === 0;
                })
            );

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $customer = $this->createCustomer();
        $customer->setPaymentCustomerId('cus_stripe_xyz');

        $repository->save($customer);
    }

    /**
     * @test
     */
    public function saveUpdatesExistingRecord(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'oe_payments_customer',
                $this->callback(function (array $data) {
                    return $data['OXID'] === 'id_123'
                        && $data['OXUSERID'] === 'user_abc';
                }),
                ['OXID' => 'id_123']
            );

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $customer = $this->createCustomer();

        $repository->save($customer);
    }

    /**
     * @test
     */
    public function findByUserIdReturnsRecordWhenFound(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('OXUSERID'),
                ['userId' => 'user_abc']
            )
            ->willReturn([
                'OXID' => 'id_123',
                'OXUSERID' => 'user_abc',
                'OXPAYMENTCUSTOMERID' => 'cus_stripe_xyz',
                'OXDEFAULTPAYMENTMETHOD' => 'pm_card_visa',
                'OXSAVEDPAYMENTMETHODS' => '["pm_card_visa"]',
                'OXBILLINGAGREEMENT' => 1,
                'OXLASTPAYMENTDATE' => '2026-02-05 10:00:00',
                'OXCREATED' => '2026-02-06 10:00:00',
                'OXUPDATED' => '2026-02-06 11:00:00',
            ]);

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $record = $repository->findByUserId('user_abc');

        $this->assertNotNull($record);
        $this->assertSame('id_123', $record->getId());
        $this->assertSame('user_abc', $record->getUserId());
        $this->assertSame('cus_stripe_xyz', $record->getPaymentCustomerId());
        $this->assertSame('pm_card_visa', $record->getDefaultPaymentMethod());
        $this->assertSame('["pm_card_visa"]', $record->getSavedPaymentMethods());
        $this->assertTrue($record->getBillingAgreement());
        $this->assertSame('2026-02-05 10:00:00', $record->getLastPaymentDate()?->format('Y-m-d H:i:s'));
    }

    /**
     * @test
     */
    public function findByUserIdReturnsNullWhenNotFound(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $result = $repository->findByUserId('nonexistent');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function findByPaymentCustomerIdReturnsRecordWhenFound(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('OXPAYMENTCUSTOMERID'),
                ['paymentCustomerId' => 'cus_stripe_xyz']
            )
            ->willReturn([
                'OXID' => 'id_123',
                'OXUSERID' => 'user_abc',
                'OXPAYMENTCUSTOMERID' => 'cus_stripe_xyz',
                'OXCREATED' => '2026-02-06 10:00:00',
                'OXUPDATED' => '2026-02-06 11:00:00',
            ]);

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $record = $repository->findByPaymentCustomerId('cus_stripe_xyz');

        $this->assertNotNull($record);
        $this->assertSame('cus_stripe_xyz', $record->getPaymentCustomerId());
    }

    /**
     * @test
     */
    public function findByUserIdReturnsNullOnException(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willThrowException(new \Doctrine\DBAL\Exception('DB error'));

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $result = $repository->findByUserId('user_abc');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function findByPaymentCustomerIdReturnsNullOnException(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willThrowException(new \Doctrine\DBAL\Exception('DB error'));

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $result = $repository->findByPaymentCustomerId('cus_xyz');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function hydratesRecordWithNullOptionalFields(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'OXID' => 'id_456',
                'OXUSERID' => 'user_def',
                'OXPAYMENTCUSTOMERID' => null,
                'OXDEFAULTPAYMENTMETHOD' => null,
                'OXSAVEDPAYMENTMETHODS' => null,
                'OXBILLINGAGREEMENT' => 0,
                'OXLASTPAYMENTDATE' => null,
                'OXCREATED' => '2026-02-06 10:00:00',
                'OXUPDATED' => '2026-02-06 10:00:00',
            ]);

        $repository = new DoctrinePaymentCustomerRepository($connection);
        $record = $repository->findByUserId('user_def');

        $this->assertNotNull($record);
        $this->assertNull($record->getPaymentCustomerId());
        $this->assertNull($record->getDefaultPaymentMethod());
        $this->assertNull($record->getSavedPaymentMethods());
        $this->assertFalse($record->getBillingAgreement());
        $this->assertNull($record->getLastPaymentDate());
    }

    private function createCustomer(): PaymentCustomer
    {
        return new PaymentCustomer(
            'id_123',
            'user_abc',
            new DateTimeImmutable('2026-02-06 10:00:00'),
            new DateTimeImmutable('2026-02-06 10:00:00')
        );
    }
}
