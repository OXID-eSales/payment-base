<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use OxidEsales\PaymentBase\Contract\ContractState;
use PHPUnit\Framework\TestCase;

class ContractStateTest extends TestCase
{
    public function testDraftFactory(): void
    {
        $state = ContractState::draft();

        $this->assertTrue($state->isDraft());
        $this->assertFalse($state->isPending());
        $this->assertEquals('draft', $state->getValue());
    }

    public function testPendingFactory(): void
    {
        $state = ContractState::pending();

        $this->assertTrue($state->isPending());
        $this->assertFalse($state->isDraft());
        $this->assertEquals('pending', $state->getValue());
    }

    public function testReadyToCommitFactory(): void
    {
        $state = ContractState::readyToCommit();

        $this->assertTrue($state->isReadyToCommit());
        $this->assertFalse($state->isPending());
        $this->assertEquals('ready_to_commit', $state->getValue());
    }

    public function testCommittedFactory(): void
    {
        $state = ContractState::committed();

        $this->assertTrue($state->isCommitted());
        $this->assertFalse($state->isReadyToCommit());
        $this->assertEquals('committed', $state->getValue());
    }

    public function testFulfilledFactory(): void
    {
        $state = ContractState::fulfilled();

        $this->assertTrue($state->isFulfilled());
        $this->assertTrue($state->isTerminal());
        $this->assertEquals('fulfilled', $state->getValue());
    }

    public function testCancelledFactory(): void
    {
        $state = ContractState::cancelled();

        $this->assertTrue($state->isTerminal());
        $this->assertEquals('cancelled', $state->getValue());
    }

    public function testExpiredFactory(): void
    {
        $state = ContractState::expired();

        $this->assertTrue($state->isTerminal());
        $this->assertEquals('expired', $state->getValue());
    }

    public function testFailedFactory(): void
    {
        $state = ContractState::failed();

        $this->assertTrue($state->isTerminal());
        $this->assertEquals('failed', $state->getValue());
    }

    public function testFromValue(): void
    {
        $state = ContractState::fromValue('pending');

        $this->assertTrue($state->isPending());
        $this->assertEquals('pending', $state->getValue());
    }

    public function testFromValueThrowsExceptionForInvalidState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid contract state: invalid_state');

        ContractState::fromValue('invalid_state');
    }

    public function testEquals(): void
    {
        $state1 = ContractState::pending();
        $state2 = ContractState::pending();
        $state3 = ContractState::draft();

        $this->assertTrue($state1->equals($state2));
        $this->assertFalse($state1->equals($state3));
    }

    public function testToString(): void
    {
        $state = ContractState::readyToCommit();

        $this->assertEquals('ready_to_commit', (string) $state);
    }

    public function testTerminalStates(): void
    {
        $this->assertTrue(ContractState::fulfilled()->isTerminal());
        $this->assertTrue(ContractState::cancelled()->isTerminal());
        $this->assertTrue(ContractState::expired()->isTerminal());
        $this->assertTrue(ContractState::failed()->isTerminal());

        $this->assertFalse(ContractState::draft()->isTerminal());
        $this->assertFalse(ContractState::pending()->isTerminal());
        $this->assertFalse(ContractState::authorized()->isTerminal());
        $this->assertFalse(ContractState::readyToCommit()->isTerminal());
        $this->assertFalse(ContractState::committed()->isTerminal());
    }

    // ==========================================
    // AUTHORIZED STATE TESTS (Sprint 1)
    // ==========================================

    public function testAuthorizedFactory(): void
    {
        $state = ContractState::authorized();

        $this->assertTrue($state->isAuthorized());
        $this->assertFalse($state->isPending());
        $this->assertFalse($state->isReadyToCommit());
        $this->assertEquals('authorized', $state->getValue());
    }

    public function testAuthorizedStateIsNotTerminal(): void
    {
        $state = ContractState::authorized();

        $this->assertFalse($state->isTerminal());
    }

    public function testAuthorizedStateEquality(): void
    {
        $state1 = ContractState::authorized();
        $state2 = ContractState::authorized();
        $state3 = ContractState::pending();

        $this->assertTrue($state1->equals($state2));
        $this->assertFalse($state1->equals($state3));
    }

    public function testAuthorizedFromValue(): void
    {
        $state = ContractState::fromValue('authorized');

        $this->assertTrue($state->isAuthorized());
        $this->assertEquals('authorized', $state->getValue());
    }

    public function testAuthorizedToString(): void
    {
        $state = ContractState::authorized();

        $this->assertEquals('authorized', (string) $state);
    }
}
