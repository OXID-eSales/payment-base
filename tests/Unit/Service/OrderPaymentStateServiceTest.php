<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Service\OrderPaymentStateService;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for OrderPaymentStateService.
 *
 * Tests follow TDD principles and verify:
 * - LSP compliance (implements interface correctly)
 * - SRP (only handles payment state updates)
 * - DRY (single implementation for all update operations)
 */
class OrderPaymentStateServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;
    private OrderPaymentStateService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new OrderPaymentStateService(
            $this->connection,
            $this->logger
        );
    }

    /**
     * LSP: Service implements interface
     */
    #[Test]
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(
            OrderPaymentStateServiceInterface::class,
            $this->service
        );
    }

    /**
     * SRP: Updates OXPAID with provided timestamp
     */
    #[Test]
    public function updatesPaidTimestampWithProvidedTime(): void
    {
        $orderId = 'order-123';
        $paidAt = new DateTimeImmutable('2025-12-09 14:30:00');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXPAID'),
                $this->callback(function ($params) use ($orderId) {
                    return $params['orderId'] === $orderId
                        && $params['paid'] === '2025-12-09 14:30:00';
                })
            )
            ->willReturn(1);

        $result = $this->service->updatePaidTimestamp($orderId, $paidAt);

        $this->assertTrue($result);
    }

    /**
     * SRP: Updates OXPAID with current time when not provided
     */
    #[Test]
    public function updatesPaidTimestampWithCurrentTimeWhenNotProvided(): void
    {
        $orderId = 'order-123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXPAID'),
                $this->callback(function ($params) use ($orderId) {
                    return $params['orderId'] === $orderId
                        && preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $params['paid']) === 1;
                })
            )
            ->willReturn(1);

        $result = $this->service->updatePaidTimestamp($orderId);

        $this->assertTrue($result);
    }

    /**
     * Returns false when order not found
     */
    #[Test]
    public function returnsFalseWhenOrderNotFound(): void
    {
        $orderId = 'non-existent';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(0);

        $result = $this->service->updatePaidTimestamp($orderId);

        $this->assertFalse($result);
    }

    /**
     * SRP: Updates OXPAID by transaction ID
     */
    #[Test]
    public function updatesPaidTimestampByTransactionId(): void
    {
        $transactionId = 'pi_123';
        $paidAt = new DateTimeImmutable('2025-12-09 14:30:00');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('WHERE OXTRANSID'),
                $this->callback(function ($params) use ($transactionId) {
                    return $params['transId'] === $transactionId
                        && $params['paid'] === '2025-12-09 14:30:00';
                })
            )
            ->willReturn(1);

        $result = $this->service->updatePaidTimestampByTransactionId($transactionId, $paidAt);

        $this->assertTrue($result);
    }

    /**
     * SRP: Updates OXTRANSSTATUS to OK
     */
    #[Test]
    public function updatesTransactionStatusToOk(): void
    {
        $orderId = 'order-123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXTRANSSTATUS'),
                $this->callback(function ($params) {
                    return $params['status'] === 'OK';
                })
            )
            ->willReturn(1);

        $result = $this->service->updateTransactionStatus($orderId, 'OK');

        $this->assertTrue($result);
    }

    /**
     * SRP: Updates OXTRANSID
     */
    #[Test]
    public function updatesTransactionId(): void
    {
        $orderId = 'order-123';
        $transactionId = 'pi_abc123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXTRANSID'),
                $this->callback(function ($params) use ($transactionId) {
                    return $params['transId'] === $transactionId;
                })
            )
            ->willReturn(1);

        $result = $this->service->updateTransactionId($orderId, $transactionId);

        $this->assertTrue($result);
    }

    /**
     * Convenience method updates all payment fields with transaction ID
     */
    #[Test]
    public function markOrderAsPaidUpdatesAllFieldsWithTransactionId(): void
    {
        $orderId = 'order-123';
        $transactionId = 'pi_abc123';
        $paidAt = new DateTimeImmutable('2025-12-09 14:30:00');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('OXPAID'),
                    $this->stringContains('OXTRANSSTATUS'),
                    $this->stringContains('OXTRANSID')
                ),
                $this->callback(function ($params) use ($orderId, $transactionId) {
                    return $params['orderId'] === $orderId
                        && $params['transId'] === $transactionId
                        && $params['paid'] === '2025-12-09 14:30:00';
                })
            )
            ->willReturn(1);

        $result = $this->service->markOrderAsPaid($orderId, $transactionId, $paidAt);

        $this->assertTrue($result);
    }

    /**
     * Convenience method updates fields without transaction ID
     */
    #[Test]
    public function markOrderAsPaidUpdatesFieldsWithoutTransactionId(): void
    {
        $orderId = 'order-123';
        $paidAt = new DateTimeImmutable('2025-12-09 14:30:00');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('OXPAID'),
                    $this->stringContains('OXTRANSSTATUS')
                ),
                $this->callback(function ($params) use ($orderId) {
                    return $params['orderId'] === $orderId
                        && $params['paid'] === '2025-12-09 14:30:00'
                        && !isset($params['transId']);
                })
            )
            ->willReturn(1);

        $result = $this->service->markOrderAsPaid($orderId, null, $paidAt);

        $this->assertTrue($result);
    }

    /**
     * Handles database exceptions gracefully
     */
    #[Test]
    public function handlesDatabaseException(): void
    {
        $orderId = 'order-123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willThrowException(new \Exception('Database error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to update OXPAID'),
                $this->callback(function ($context) use ($orderId) {
                    return $context['order_id'] === $orderId
                        && $context['error'] === 'Database error';
                })
            );

        $result = $this->service->updatePaidTimestamp($orderId);

        $this->assertFalse($result);
    }

    /**
     * Logs successful update
     */
    #[Test]
    public function logsSuccessfulUpdate(): void
    {
        $orderId = 'order-123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'OXPAID timestamp updated',
                $this->callback(function ($context) use ($orderId) {
                    return $context['order_id'] === $orderId;
                })
            );

        $this->service->updatePaidTimestamp($orderId);
    }

    /**
     * Does not log when no rows affected
     */
    #[Test]
    public function doesNotLogWhenNoRowsAffected(): void
    {
        $orderId = 'order-123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(0);

        $this->logger
            ->expects($this->never())
            ->method('debug');

        $this->service->updatePaidTimestamp($orderId);
    }
}
