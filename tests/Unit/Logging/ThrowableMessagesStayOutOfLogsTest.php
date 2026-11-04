<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Logging;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Checkout\Context\SessionCheckoutContext;
use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleaner;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Handler\UserBasketRemovalHandler;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\ContractOrderMailStoreInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineNotFinishedOrderRepository;
use OxidEsales\PaymentBase\Repository\DoctrineWebhookLogRepository;
use OxidEsales\PaymentBase\Service\OrderPaymentStateService;
use OxidEsales\PaymentBase\Tests\Unit\Checkout\RecordingSessionAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * VR-25 (sprint 18 L1 follow-up): what a database or a provider throws can carry the statement's bound parameters - a
 * customer's e-mail, an address. A log line, or an exception message that callers log, names the throwable by summary.
 */
final class ThrowableMessagesStayOutOfLogsTest extends TestCase
{
    private const SECRET = 'jane@example.test';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testAFailedPaidStampLogsTheSummaryNotTheDatabaseMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException($this->leakyDatabaseError());
        $service = new OrderPaymentStateService($connection, $this->logger, new ThrowableSummary([]));

        self::assertFalse($service->updatePaidTimestamp('order-1'));

        $this->assertLoggedTheSummaryOnly('42S22');
    }

    public function testTheStateAndProviderQueryRefusalCarriesTheSummaryLine(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willThrowException($this->leakyDatabaseError());
        $repository = new DoctrineContractRepository($connection, $this->logger, new ThrowableSummary([]));

        try {
            $repository->findByStateAndProvider('pending', 'mollie');
            self::fail('expected a refusal');
        } catch (RuntimeException $refusal) {
            self::assertStringNotContainsString(self::SECRET, $refusal->getMessage());
            self::assertStringContainsString('SQLSTATE 42S22', $refusal->getMessage());
        }
    }

    public function testAWebhookLogWriteRefusalCarriesTheSummaryLine(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('update')->willThrowException($this->leakyDatabaseError());
        $repository = new DoctrineWebhookLogRepository($connection, new ThrowableSummary([]));

        try {
            $repository->updateStatus('evt_1', 'processed');
            self::fail('expected a refusal');
        } catch (RuntimeException $refusal) {
            self::assertStringNotContainsString(self::SECRET, $refusal->getMessage());
        }
    }

    public function testTheNotFinishedOrderQueryRefusalCarriesTheSummaryLine(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException($this->leakyDatabaseError());
        $connection->method('fetchAllAssociative')->willThrowException($this->leakyDatabaseError());
        $repository = new DoctrineNotFinishedOrderRepository(
            $connection,
            $this->createStub(ContractOrderMailStoreInterface::class),
            new ThrowableSummary([])
        );

        try {
            $repository->findStaleNotFinishedOrderIds(7);
            self::fail('expected a refusal');
        } catch (RuntimeException $refusal) {
            self::assertStringNotContainsString(self::SECRET, $refusal->getMessage());
        }
    }

    public function testABasketThatCannotBeDeletedLogsTheSummary(): void
    {
        $handler = new class ($this->logger, new ThrowableSummary([])) extends UserBasketRemovalHandler {
            protected function deleteUserBasket(string $basketId): bool
            {
                throw new RuntimeException('with params ["' . ThrowableMessagesStayOutOfLogsTest::SECRET . '"]', 5);
            }
        };
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getMetadata')->willReturn('basket-1');
        $contract->method('getId')->willReturn('contract-1');

        $handler->handle(new ContractCommittedEvent($contract, new EventContext(), 'order-1'));

        $this->assertLoggedTheSummaryOnly(null);
    }

    public function testAnOrderThatCannotBeRemovedLogsTheSummary(): void
    {
        $orders = $this->createMock(ShopOrderServiceInterface::class);
        $orders->method('deleteNotFinishedOrder')->willThrowException($this->leakyDatabaseError());
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::pending());
        $contract->method('getStateValue')->willReturn('pending');
        $contract->method('getOrderId')->willReturn('order-1');
        $contracts = $this->createStub(ContractRepositoryInterface::class);
        $contracts->method('findById')->willReturn($contract);
        $cleaner = new PreviousCheckoutAttemptCleaner(
            $contracts,
            $orders,
            $this->logger,
            new SessionCheckoutContext(new RecordingSessionAdapter()),
            new ThrowableSummary([])
        );

        $cleaner->clean('contract-1');

        $this->assertLoggedTheSummaryOnly('42S22');
    }

    private function assertLoggedTheSummaryOnly(?string $sqlState): void
    {
        self::assertNotSame([], $this->logger->records, 'something must have been logged');
        $everything = json_encode($this->logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $everything);
        $context = $this->logger->records[0]['context'];
        self::assertIsArray($context['throwable'] ?? null);
        self::assertArrayNotHasKey('error', $context);
        if ($sqlState !== null) {
            self::assertSame($sqlState, $context['throwable']['sqlState']);
        }
    }

    private function leakyDatabaseError(): Throwable
    {
        return new class ('with params ["' . self::SECRET . '"]: SQLSTATE[42S22]', 1054) extends DbalException {
            public function getSQLState(): string
            {
                return '42S22';
            }
        };
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
