<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Attempt;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Checkout\Attempt\OrderCreationClaim;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 143 (AW) / MOL-39 - one order creation per contract and row version.
 */
final class OrderCreationClaimTest extends TestCase
{
    private IdempotencyRepositoryInterface&MockObject $records;

    protected function setUp(): void
    {
        $this->records = $this->createMock(IdempotencyRepositoryInterface::class);
    }

    public function testClaim_WhenTheRecordIsFree_ReturnsTrue(): void
    {
        $this->records->method('claim')->willReturn(true);

        self::assertTrue((new OrderCreationClaim($this->records))->claim($this->contract('c1', 3)));
    }

    public function testClaim_WhenAnotherRequestHoldsTheRecord_ReturnsFalse(): void
    {
        $this->records->method('claim')->willReturn(false);

        self::assertFalse((new OrderCreationClaim($this->records))->claim($this->contract('c1', 3)));
    }

    public function testClaim_ForAContract_KeysTheRecordOnContractIdAndRowVersion(): void
    {
        $claimed = null;
        $this->records->expects(self::once())->method('claim')
            ->willReturnCallback(static function (IdempotencyRecord $record) use (&$claimed): bool {
                $claimed = $record;

                return true;
            });

        (new OrderCreationClaim($this->records))->claim($this->contract('c1', 3));

        self::assertInstanceOf(IdempotencyRecord::class, $claimed);
        self::assertSame('order-creation:c1:v3', $claimed->getKey());
        self::assertSame(md5('order-creation:c1:v3'), $claimed->getId());
        self::assertSame('early-order-create', $claimed->getOperation());
        self::assertGreaterThan(new DateTimeImmutable(), $claimed->getExpiresAt());
    }

    /**
     * OPC-175: a contract reopened for a new attempt is a draft again and gets its order rewritten. The
     * reopened copy is a later row version, so the claim of the first attempt does not refuse it.
     */
    public function testClaim_ForALaterRowVersion_UsesAnotherKey(): void
    {
        $keys = [];
        $this->records->method('claim')
            ->willReturnCallback(static function (IdempotencyRecord $record) use (&$keys): bool {
                $keys[] = $record->getKey();

                return true;
            });
        $claim = new OrderCreationClaim($this->records);

        $claim->claim($this->contract('c1', 3));
        $claim->claim($this->contract('c1', 5));

        self::assertSame(['order-creation:c1:v3', 'order-creation:c1:v5'], $keys);
    }

    public function testRelease_ForAClaimedContract_SavesTheSameRecordAsExpired(): void
    {
        $released = null;
        $this->records->expects(self::once())->method('save')
            ->willReturnCallback(static function (IdempotencyRecord $record) use (&$released): void {
                $released = $record;
            });

        (new OrderCreationClaim($this->records))->release($this->contract('c1', 3));

        self::assertInstanceOf(IdempotencyRecord::class, $released);
        self::assertSame(md5('order-creation:c1:v3'), $released->getId());
        self::assertLessThan(new DateTimeImmutable(), $released->getExpiresAt());
    }

    private function contract(string $id, int $version): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('toArray')->willReturn(['version' => $version]);

        return $contract;
    }
}
