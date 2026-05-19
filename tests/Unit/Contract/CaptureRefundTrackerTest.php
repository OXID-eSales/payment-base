<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\CaptureRefundTracker;
use OxidEsales\PaymentBase\Contract\ContractState;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 01a — extraction of capture/refund tracking off PaymentContract.
 *
 * Behaviour previously asserted on PaymentContract directly (see
 * PaymentContractTest::testSetCapturedAmount*, ::testAddRefundedAmount*)
 * is mirrored here on the new collaborator to keep the unit
 * coverage close to the new home of the logic. The outer aggregate
 * (PaymentContract) keeps its own integration coverage; this file is
 * the dedicated unit-level safety net for the extracted class.
 */
class CaptureRefundTrackerTest extends TestCase
{
    public function testInitialStateIsAllNull(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->assertNull($tracker->getCapturedAmount());
        $this->assertNull($tracker->getRefundedAmount());
        $this->assertNull($tracker->getCapturedAt());
        $this->assertNull($tracker->getRefundedAt());
    }

    public function testSetCapturedAmountStoresValueInFulfilledState(): void
    {
        $tracker = new CaptureRefundTracker();

        $tracker->setCapturedAmount(ContractState::fulfilled(), 100.0);

        $this->assertSame(100.0, $tracker->getCapturedAmount());
    }

    public function testSetCapturedAmountIsAllowedInPendingState(): void
    {
        // STRP-AUTOCAP-REFUND: PSP webhook delivery is out of order; the
        // captured-amount write must succeed even before the contract has
        // transitioned out of PENDING.
        $tracker = new CaptureRefundTracker();

        $tracker->setCapturedAmount(ContractState::pending(), 100.0);

        $this->assertSame(100.0, $tracker->getCapturedAmount());
    }

    public function testSetCapturedAmountIsAllowedInAuthorizedState(): void
    {
        $tracker = new CaptureRefundTracker();

        $tracker->setCapturedAmount(ContractState::authorized(), 100.0);

        $this->assertSame(100.0, $tracker->getCapturedAmount());
    }

    public function testSetCapturedAmountIsAllowedInReadyToCommitState(): void
    {
        $tracker = new CaptureRefundTracker();

        $tracker->setCapturedAmount(ContractState::readyToCommit(), 100.0);

        $this->assertSame(100.0, $tracker->getCapturedAmount());
    }

    public function testSetCapturedAmountIsAllowedInCommittedState(): void
    {
        $tracker = new CaptureRefundTracker();

        $tracker->setCapturedAmount(ContractState::committed(), 100.0);

        $this->assertSame(100.0, $tracker->getCapturedAmount());
    }

    public function testSetCapturedAmountThrowsInDraftState(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot set captured amount in state draft');

        $tracker->setCapturedAmount(ContractState::draft(), 100.0);
    }

    public function testSetCapturedAmountThrowsInNotFinishedState(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot set captured amount in state not_finished');

        $tracker->setCapturedAmount(ContractState::notFinished(), 100.0);
    }

    public function testSetCapturedAmountThrowsInCancelledState(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot set captured amount in state cancelled');

        $tracker->setCapturedAmount(ContractState::cancelled(), 100.0);
    }

    public function testSetCapturedAmountRejectsNegative(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $tracker->setCapturedAmount(ContractState::fulfilled(), -1.0);
    }

    public function testSetCapturedAmountRejectsZero(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $tracker->setCapturedAmount(ContractState::fulfilled(), 0.0);
    }

    public function testSetCapturedAmountRejectsInfinity(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $tracker->setCapturedAmount(ContractState::fulfilled(), INF);
    }

    public function testSetCapturedAmountRejectsNan(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $tracker->setCapturedAmount(ContractState::fulfilled(), NAN);
    }

    public function testAddRefundedAmountAllowedInFulfilledState(): void
    {
        $tracker = new CaptureRefundTracker();

        $tracker->addRefundedAmount(ContractState::fulfilled(), 25.0);

        $this->assertSame(25.0, $tracker->getRefundedAmount());
    }

    public function testAddRefundedAmountAccumulates(): void
    {
        $tracker = new CaptureRefundTracker();

        $tracker->addRefundedAmount(ContractState::fulfilled(), 30.0);
        $tracker->addRefundedAmount(ContractState::fulfilled(), 20.0);

        $this->assertSame(50.0, $tracker->getRefundedAmount());
    }

    public function testAddRefundedAmountThrowsInNonFulfilledState(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FULFILLED');

        $tracker->addRefundedAmount(ContractState::committed(), 10.0);
    }

    public function testAddRefundedAmountRejectsNegative(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $tracker->addRefundedAmount(ContractState::fulfilled(), -1.0);
    }

    public function testAddRefundedAmountRejectsZero(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $tracker->addRefundedAmount(ContractState::fulfilled(), 0.0);
    }

    public function testAddRefundedAmountRejectsNan(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $tracker->addRefundedAmount(ContractState::fulfilled(), NAN);
    }

    public function testCapturedAtStoresAndReturnsValue(): void
    {
        $tracker = new CaptureRefundTracker();
        $when = new DateTimeImmutable('2026-05-19 10:00:00');

        $tracker->setCapturedAt($when);

        $this->assertSame($when, $tracker->getCapturedAt());
    }

    public function testRefundedAtStoresAndReturnsValue(): void
    {
        $tracker = new CaptureRefundTracker();
        $when = new DateTimeImmutable('2026-05-19 11:00:00');

        $tracker->setRefundedAt($when);

        $this->assertSame($when, $tracker->getRefundedAt());
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $tracker = new CaptureRefundTracker();
        $tracker->setCapturedAmount(ContractState::fulfilled(), 100.0);
        $tracker->addRefundedAmount(ContractState::fulfilled(), 25.0);
        $tracker->setCapturedAt(new DateTimeImmutable('2026-05-19 10:00:00'));
        $tracker->setRefundedAt(new DateTimeImmutable('2026-05-19 11:00:00'));

        $hydrated = CaptureRefundTracker::fromArray($tracker->toArray());

        $this->assertSame(100.0, $hydrated->getCapturedAmount());
        $this->assertSame(25.0, $hydrated->getRefundedAmount());
        $this->assertSame('2026-05-19 10:00:00', $hydrated->getCapturedAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-19 11:00:00', $hydrated->getRefundedAt()?->format('Y-m-d H:i:s'));
    }

    public function testFromArrayWithEmptyArrayProducesAllNulls(): void
    {
        $tracker = CaptureRefundTracker::fromArray([]);

        $this->assertNull($tracker->getCapturedAmount());
        $this->assertNull($tracker->getRefundedAmount());
        $this->assertNull($tracker->getCapturedAt());
        $this->assertNull($tracker->getRefundedAt());
    }

    // ==========================================
    // Sprint 01 — getRemainingRefundableAmount() & isFullyRefunded()
    // ==========================================

    public function testGetRemainingRefundableAmountReturnsNullWhenCapturedIsNull(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->assertNull($tracker->getRemainingRefundableAmount());
    }

    public function testGetRemainingRefundableAmountReturnsCapturedWhenNothingRefunded(): void
    {
        $tracker = new CaptureRefundTracker();
        $tracker->setCapturedAmount(ContractState::fulfilled(), 100.0);

        $this->assertSame(100.0, $tracker->getRemainingRefundableAmount());
    }

    public function testGetRemainingRefundableAmountReturnsCapturedMinusRefunded(): void
    {
        $tracker = new CaptureRefundTracker();
        $tracker->setCapturedAmount(ContractState::fulfilled(), 100.0);
        $tracker->addRefundedAmount(ContractState::fulfilled(), 30.0);

        $this->assertSame(70.0, $tracker->getRemainingRefundableAmount());
    }

    public function testGetRemainingRefundableAmountIsZeroWhenFullyRefunded(): void
    {
        $tracker = new CaptureRefundTracker();
        $tracker->setCapturedAmount(ContractState::fulfilled(), 100.0);
        $tracker->addRefundedAmount(ContractState::fulfilled(), 100.0);

        $this->assertSame(0.0, $tracker->getRemainingRefundableAmount());
    }

    public function testGetRemainingRefundableAmountClampsToZeroWhenOverRefunded(): void
    {
        // Defensive: in normal flow the contract rejects over-refund,
        // but storage layer might still produce captured 10 vs refunded
        // 12 after migration / manual reconciliation. The query must
        // never return a negative remaining amount.
        $tracker = new CaptureRefundTracker();
        $tracker->setCapturedAmount(ContractState::fulfilled(), 10.0);
        $tracker = CaptureRefundTracker::fromArray([
            'capturedAmount' => 10.0,
            'refundedAmount' => 12.0,
        ]);

        $this->assertSame(0.0, $tracker->getRemainingRefundableAmount());
    }

    public function testGetRemainingRefundableAmountTreatsSubEpsilonAsZero(): void
    {
        // captured 100.00, refunded 99.999 — float noise; remaining
        // must be 0.0, not 0.001.
        $tracker = CaptureRefundTracker::fromArray([
            'capturedAmount' => 100.00,
            'refundedAmount' => 99.999,
        ]);

        $this->assertSame(0.0, $tracker->getRemainingRefundableAmount());
    }

    public function testIsFullyRefundedFalseWhenNoCapture(): void
    {
        $tracker = new CaptureRefundTracker();

        $this->assertFalse($tracker->isFullyRefunded());
    }

    public function testIsFullyRefundedFalseWhenPartiallyRefunded(): void
    {
        $tracker = new CaptureRefundTracker();
        $tracker->setCapturedAmount(ContractState::fulfilled(), 100.0);
        $tracker->addRefundedAmount(ContractState::fulfilled(), 30.0);

        $this->assertFalse($tracker->isFullyRefunded());
    }

    public function testIsFullyRefundedTrueWhenRefundedEqualsCaptured(): void
    {
        $tracker = new CaptureRefundTracker();
        $tracker->setCapturedAmount(ContractState::fulfilled(), 100.0);
        $tracker->addRefundedAmount(ContractState::fulfilled(), 100.0);

        $this->assertTrue($tracker->isFullyRefunded());
    }

    public function testIsFullyRefundedTrueWhenRefundedExceedsCapturedBySubEpsilon(): void
    {
        $tracker = CaptureRefundTracker::fromArray([
            'capturedAmount' => 100.00,
            'refundedAmount' => 100.001,
        ]);

        $this->assertTrue($tracker->isFullyRefunded());
    }

    public function testIsFullyRefundedFalseWhenCapturedIsZeroLike(): void
    {
        // Captured null and refunded null both mean "nothing happened
        // yet", NOT "fully refunded". Same for any null on either side.
        $tracker = CaptureRefundTracker::fromArray([
            'capturedAmount' => null,
            'refundedAmount' => null,
        ]);

        $this->assertFalse($tracker->isFullyRefunded());
    }
}
