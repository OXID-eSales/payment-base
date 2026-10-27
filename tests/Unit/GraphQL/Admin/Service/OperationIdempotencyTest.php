<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\IdempotencyClaim;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotency;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryIdempotency;
use PHPUnit\Framework\TestCase;

final class OperationIdempotencyTest extends TestCase
{
    private InMemoryIdempotency $records;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->records = new InMemoryIdempotency();
        $this->now = new DateTimeImmutable('2026-10-26 10:00:00');
    }

    public function testClaim_FirstCall_IsFreshAndLeavesAnInFlightRecord(): void
    {
        $claim = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        self::assertSame(IdempotencyClaim::FRESH, $claim->outcome);
        self::assertCount(1, $this->records->records);
        $record = array_values($this->records->records)[0];
        self::assertSame('in_flight', $record->getStatus());
        self::assertSame('order-1', $record->getOrderId());
        self::assertSame('admin_payment_refund', $record->getOperation());
        self::assertLessThanOrEqual(128, strlen($record->getKey()), 'OXKEY is VARCHAR(128)');
        self::assertSame(32, strlen($record->getId()), 'OXID is CHAR(32)');
    }

    public function testClaim_SameKeyWhileTheFirstRuns_IsInFlight(): void
    {
        $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        $second = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        self::assertSame(IdempotencyClaim::IN_FLIGHT, $second->outcome);
    }

    public function testClaim_SameKeyAfterCompletion_IsACompletedReplay(): void
    {
        $first = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');
        $this->idempotency()->complete($first);

        $replay = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        self::assertSame(IdempotencyClaim::COMPLETED, $replay->outcome);
    }

    public function testClaim_AfterRelease_AllowsTheNextAttempt(): void
    {
        $first = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');
        $this->idempotency()->release($first);

        $retry = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        self::assertSame(IdempotencyClaim::FRESH, $retry->outcome);
    }

    public function testClaim_InFlightRecordPastItsWindow_IsTakenOver(): void
    {
        $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');
        $this->now = $this->now->modify('+3 minutes');

        $later = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        self::assertSame(IdempotencyClaim::FRESH, $later->outcome, 'a crashed request must not block the order for ever');
    }

    public function testClaim_CompletedRecord_IsKeptForADay(): void
    {
        $first = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');
        $this->idempotency()->complete($first);

        $this->now = $this->now->modify('+23 hours');
        self::assertSame(
            IdempotencyClaim::COMPLETED,
            $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1')->outcome,
        );

        $this->now = $this->now->modify('+2 hours');
        self::assertSame(
            IdempotencyClaim::FRESH,
            $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1')->outcome,
        );
    }

    public function testClaim_AnotherKeyOperationOrOrder_IsIndependent(): void
    {
        $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        self::assertSame(IdempotencyClaim::FRESH, $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-2')->outcome);
        self::assertSame(IdempotencyClaim::FRESH, $this->idempotency()->claim('admin_payment_capture', 'order-1', 'key-1')->outcome);
        self::assertSame(IdempotencyClaim::FRESH, $this->idempotency()->claim('admin_payment_refund', 'order-2', 'key-1')->outcome);
    }

    public function testClaim_ARequestThatInsertedBetweenOurReadAndInsert_MakesUsLoseTheRace(): void
    {
        $key = $this->idempotency()->keyFor('admin_payment_refund', 'order-1', 'key-1');
        $this->records->racingInsertFor = $key;

        $claim = $this->idempotency()->claim('admin_payment_refund', 'order-1', 'key-1');

        self::assertSame(IdempotencyClaim::IN_FLIGHT, $claim->outcome);
    }

    private function idempotency(): OperationIdempotency
    {
        $now = $this->now;

        return new OperationIdempotency($this->records, static fn (): DateTimeImmutable => $now);
    }
}
