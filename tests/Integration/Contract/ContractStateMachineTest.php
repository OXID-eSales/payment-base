<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\Contract;

use DomainException;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for PaymentContract state machine.
 *
 * Tests all valid state transitions and verifies invalid transitions
 * throw DomainException.
 *
 * State Machine Flow (STRP-74 updated):
 * DRAFT → NOT_FINISHED → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
 *              ↓              ↓              ↓               ↓
 *           FAILED         FAILED       CANCELLED       EXPIRED
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @covers \OxidEsales\PaymentComponent\Contract\ContractState
 * @group sprint-14
 * @group contract
 * @group state-machine
 */
final class ContractStateMachineTest extends TestCase
{
    private BasketSnapshot $basketSnapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basketSnapshot = BasketSnapshot::fromArray([
            'items' => [['id' => 'item1', 'qty' => 1, 'price' => 100.00]],
            'discounts' => [],
            'totalGross' => 100.00,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
    }

    // ==========================================
    // Happy Path: Full Lifecycle
    // ==========================================

    /**
     * @test
     */
    public function fullLifecycleFromDraftToFulfilled(): void
    {
        // Given: New contract starts in DRAFT
        $contract = new PaymentContract(1, 'user123', $this->basketSnapshot);
        $this->assertTrue($contract->getState()->isDraft());

        // When: Add condition and transition to NOT_FINISHED then PENDING
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_abc123');
        $contract->transitionToPending();
        $this->assertTrue($contract->getState()->isPending());

        // When: Fulfill condition → READY_TO_COMMIT
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['pi' => 'pi_123']);
        $this->assertTrue($contract->getState()->isReadyToCommit());

        // When: Commit to order → COMMITTED
        $contract->commitToOrder('order_abc123');
        $this->assertTrue($contract->getState()->isCommitted());
        $this->assertEquals('order_abc123', $contract->getOrderId());

        // When: Fulfill → FULFILLED
        $contract->fulfill();
        $this->assertTrue($contract->getState()->isFulfilled());
        $this->assertNotNull($contract->getFulfilledAt());
    }

    // ==========================================
    // State Transitions
    // ==========================================

    /**
     * @test
     * STRP-74: Updated for new flow - transitionToNotFinished checks for conditions
     */
    public function transitionToNotFinishedRequiresConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->basketSnapshot);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot transition to NOT_FINISHED without conditions');

        $contract->transitionToNotFinished('order_123');
    }

    /**
     * @test
     * STRP-74: transitionToPending now requires NOT_FINISHED state, not DRAFT
     */
    public function transitionToPendingRequiresNotFinishedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->basketSnapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Can only transition to PENDING from NOT_FINISHED state');

        $contract->transitionToPending();
    }

    /**
     * @test
     * STRP-74: Updated for new flow - transitionToPending only from NOT_FINISHED
     */
    public function transitionToPendingOnlyFromNotFinished(): void
    {
        $contract = $this->createContractInState('pending');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Can only transition to PENDING from NOT_FINISHED state');

        $contract->transitionToPending();
    }

    /**
     * @test
     */
    public function fulfillConditionTransitionsToReadyToCommitWhenAllFulfilled(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->basketSnapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        // Fulfill first condition - still PENDING
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $this->assertTrue($contract->getState()->isPending());

        // Fulfill second condition - now READY_TO_COMMIT
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK);
        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

    /**
     * @test
     */
    public function commitToOrderRequiresReadyToCommitState(): void
    {
        $contract = $this->createContractInState('pending');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contract must be in READY_TO_COMMIT state to commit');

        $contract->commitToOrder('order123');
    }

    /**
     * @test
     */
    public function fulfillRequiresCommittedState(): void
    {
        $contract = $this->createContractInState('ready_to_commit');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contract must be COMMITTED before fulfillment');

        $contract->fulfill();
    }

    // ==========================================
    // Terminal States
    // ==========================================

    /**
     * @test
     */
    public function cannotTransitionFromFulfilled(): void
    {
        $contract = $this->createContractInState('fulfilled');

        $this->assertTrue($contract->getState()->isTerminal());
        $this->assertTrue($contract->getState()->isFulfilled());

        $this->expectException(DomainException::class);
        $contract->cancel();
    }

    /**
     * @test
     */
    public function cancelledIsFinal(): void
    {
        $contract = $this->createContractInState('pending');
        $contract->cancel('User cancelled');

        $this->assertTrue($contract->getState()->isCancelled());
        $this->assertTrue($contract->getState()->isTerminal());

        $this->expectException(DomainException::class);
        $contract->fail('Cannot fail cancelled contract');
    }

    /**
     * @test
     */
    public function expiredIsFinal(): void
    {
        $contract = $this->createContractInState('pending');
        $contract->expire();

        $this->assertTrue($contract->getState()->isExpired());
        $this->assertTrue($contract->getState()->isTerminal());

        $this->expectException(DomainException::class);
        $contract->cancel();
    }

    /**
     * @test
     */
    public function failedIsFinal(): void
    {
        $contract = $this->createContractInState('pending');
        $contract->fail('Payment failed');

        $this->assertTrue($contract->getState()->isFailed());
        $this->assertTrue($contract->getState()->isTerminal());

        $this->expectException(DomainException::class);
        $contract->expire();
    }

    // ==========================================
    // Invalid Transitions
    // ==========================================

    /**
     * @test
     */
    public function cannotAddConditionsAfterDraft(): void
    {
        $contract = $this->createContractInState('pending');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot add conditions after DRAFT state');

        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));
    }

    /**
     * @test
     */
    public function cannotCommitWithUnfulfilledConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->basketSnapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        // Only fulfill one condition
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        // Still PENDING (not READY_TO_COMMIT) because not all conditions fulfilled
        $this->assertTrue($contract->getState()->isPending());

        // Cannot commit from PENDING
        $this->expectException(DomainException::class);
        $contract->commitToOrder('order123');
    }

    /**
     * @test
     */
    public function cannotFulfillFromPending(): void
    {
        $contract = $this->createContractInState('pending');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contract must be COMMITTED before fulfillment');

        $contract->fulfill();
    }

    /**
     * @test
     */
    public function cannotFulfillFromReadyToCommit(): void
    {
        $contract = $this->createContractInState('ready_to_commit');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contract must be COMMITTED before fulfillment');

        $contract->fulfill();
    }

    // ==========================================
    // ContractState Value Object
    // ==========================================

    /**
     * @test
     */
    public function contractStateEquality(): void
    {
        $state1 = ContractState::committed();
        $state2 = ContractState::committed();
        $state3 = ContractState::fulfilled();

        $this->assertTrue($state1->equals($state2));
        $this->assertFalse($state1->equals($state3));
    }

    /**
     * @test
     */
    public function contractStateFromValue(): void
    {
        $state = ContractState::fromValue('committed');

        $this->assertTrue($state->isCommitted());
        $this->assertEquals('committed', $state->getValue());
        $this->assertEquals('committed', (string) $state);
    }

    /**
     * @test
     */
    public function contractStateInvalidValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid contract state: invalid_state');

        ContractState::fromValue('invalid_state');
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Create a contract in a specific state for testing.
     */
    private function createContractInState(string $state): PaymentContract
    {
        $contract = new PaymentContract(1, 'user123', $this->basketSnapshot);

        if ($state === 'draft') {
            return $contract;
        }

        // Add condition to allow state transitions
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        if ($state === 'pending') {
            $contract->transitionToNotFinished('order_123');
            $contract->transitionToPending();
            return $contract;
        }

        $orderId = 'order_test_' . uniqid();
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        if ($state === 'ready_to_commit') {
            return $contract;
        }

        $contract->commitToOrder($orderId);

        if ($state === 'committed') {
            return $contract;
        }

        if ($state === 'fulfilled') {
            $contract->fulfill();
            return $contract;
        }

        throw new \InvalidArgumentException("Unknown state: {$state}");
    }
}
