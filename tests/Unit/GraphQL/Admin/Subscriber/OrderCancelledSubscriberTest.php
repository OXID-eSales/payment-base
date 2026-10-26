<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderCancelled;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\GraphQL\Admin\Subscriber\OrderCancelledSubscriber;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class OrderCancelledSubscriberTest extends TestCase
{
    public function testSubscribesToTheCancelEvent(): void
    {
        self::assertSame(
            [AdminOrderCancelled::class => 'onOrderCancelled'],
            OrderCancelledSubscriber::getSubscribedEvents(),
        );
    }

    public function testOnOrderCancelled_CapturedPayment_OnlyPointsAtRefund_AndChangesNothing(): void
    {
        $contracts = new InMemoryContracts();
        $contract = ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0, 20.0);
        $contracts->save($contract);
        $before = $contract->toArray();
        $log = $this->recordingLogger();

        (new OrderCancelledSubscriber($this->reader($contracts), $log))->onOrderCancelled($this->event('order-1'));

        self::assertCount(1, $log->records);
        self::assertSame('warning', $log->records[0]['level']);
        self::assertSame('order-1', $log->records[0]['context']['orderId']);
        self::assertSame('refund', $log->records[0]['context']['offer']);
        self::assertSame(80.0, $log->records[0]['context']['refundable']);
        self::assertEquals($before['state'], $contract->toArray()['state'], 'cancelling an order never moves money');
        self::assertSame(20.0, $contract->getRefundedAmount());
    }

    public function testOnOrderCancelled_OpenAuthorisation_OnlyPointsAtVoid(): void
    {
        $contracts = new InMemoryContracts();
        $contracts->save(ContractFactory::authorized('order-2'));
        $log = $this->recordingLogger();

        (new OrderCancelledSubscriber($this->reader($contracts), $log))->onOrderCancelled($this->event('order-2'));

        self::assertCount(1, $log->records);
        self::assertSame('void', $log->records[0]['context']['offer']);
    }

    public function testOnOrderCancelled_NothingToSettle_SaysNothing(): void
    {
        $contracts = new InMemoryContracts();
        $contracts->save(ContractFactory::fulfilled('order-3', 'paypal', 100.0, 100.0, 100.0));
        $log = $this->recordingLogger();

        (new OrderCancelledSubscriber($this->reader($contracts), $log))->onOrderCancelled($this->event('order-3'));
        (new OrderCancelledSubscriber($this->reader($contracts), $log))->onOrderCancelled($this->event('order-without-contract'));

        self::assertSame([], $log->records);
    }

    private function reader(InMemoryContracts $contracts): PaymentContractDetailsReader
    {
        return new PaymentContractDetailsReader($contracts, $this->createStub(TransactionRepositoryInterface::class));
    }

    private function event(string $orderId): AdminOrderCancelled
    {
        return new AdminOrderCancelled($orderId, 1, 'oe_payments_paypal', 'tx-1');
    }

    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };
    }
}
