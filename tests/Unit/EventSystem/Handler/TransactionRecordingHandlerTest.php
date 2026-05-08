<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\TransactionRecordingHandler;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class TransactionRecordingHandlerTest extends TestCase
{
    public function testInsertsAuthorizationRow(): void
    {
        $saved = null;
        $repo = $this->createMock(TransactionRepositoryInterface::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (Transaction $t) use (&$saved): void {
                $saved = $t;
            });

        $context = new EventContext([
            'providerName' => 'stripe',
            'orderId' => 'oxid_ord_1',
        ]);
        $event = new PaymentAuthorizedEvent($context, 'auth_1', 'stripe_ord_1', 12.5, 'EUR');

        (new TransactionRecordingHandler($repo))->handle($event);

        self::assertInstanceOf(Transaction::class, $saved);
        self::assertSame('oxid_ord_1', $saved->getOrderId());
        self::assertSame('stripe', $saved->getProvider());
        self::assertSame('authorization', $saved->getType());
        self::assertSame('completed', $saved->getStatus());
        self::assertSame(12.5, $saved->getAmount());
        self::assertSame('EUR', $saved->getCurrency());
        self::assertSame('auth_1', $saved->getTransactionId());
        self::assertSame('stripe_ord_1', $saved->getProviderOrderId());
    }

    public function testSkipsWhenNoOrderId(): void
    {
        $repo = $this->createMock(TransactionRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $event = new PaymentAuthorizedEvent(
            new EventContext(['providerName' => 'stripe']),
            'auth_1',
            'stripe_ord_1',
            10.0,
            'EUR',
        );

        (new TransactionRecordingHandler($repo))->handle($event);
    }

    public function testGatedOnProviderName(): void
    {
        $repo = $this->createMock(TransactionRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $event = new PaymentAuthorizedEvent(
            new EventContext(['orderId' => 'oxid_ord_1']),
            'auth_1',
            'stripe_ord_1',
            10.0,
            'EUR',
        );
        (new TransactionRecordingHandler($repo))->handle($event);
    }

    public function testIgnoresUnrelatedEvent(): void
    {
        $repo = $this->createMock(TransactionRepositoryInterface::class);
        $repo->expects(self::never())->method('save');
        (new TransactionRecordingHandler($repo))->handle(new \stdClass());
        self::assertTrue(true);
    }
}
