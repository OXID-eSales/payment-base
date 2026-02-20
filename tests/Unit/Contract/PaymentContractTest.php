<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Contract;

use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractState;
use PHPUnit\Framework\TestCase;

class PaymentContractTest extends TestCase
{
    private function createBasketSnapshot(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testConstruct(): void
    {
        $snapshot = $this->createBasketSnapshot();
        $contract = new PaymentContract(1, 'user123', $snapshot);

        $this->assertNotNull($contract->getId());
        $this->assertEquals(1, $contract->getShopId());
        $this->assertEquals('user123', $contract->getUserId());
        $this->assertNull($contract->getOrderId());
        $this->assertTrue($contract->getState()->isDraft());
        $this->assertInstanceOf(BasketSnapshot::class, $contract->getBasketSnapshot());
        $this->assertNotNull($contract->getCreatedAt());
        $this->assertNotNull($contract->getExpiresAt());
    }

    public function testConstructWithCustomId(): void
    {
        $snapshot = $this->createBasketSnapshot();
        $contract = new PaymentContract(1, 'user123', $snapshot, 'custom_id');

        $this->assertEquals('custom_id', $contract->getId());
    }

    public function testAddCondition(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $contract->addCondition($condition);

        $this->assertCount(1, $contract->getConditions());
    }

    public function testAddConditionAfterDraftThrowsException(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->addCondition($condition);
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot add conditions after DRAFT state');

        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));
    }

    public function testTransitionToPendingRequiresNotFinishedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only transition to PENDING from NOT_FINISHED state');

        $contract->transitionToPending();
    }

    public function testTransitionToPending(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $contract->transitionToPending();

        $this->assertTrue($contract->getState()->isPending());
    }

    public function testTransitionToPendingOnlyFromNotFinished(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only transition to PENDING from NOT_FINISHED state');

        $contract->transitionToPending();
    }

    public function testFulfillCondition(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => '123']);

        $conditions = $contract->getConditions();
        $this->assertTrue($conditions[0]->isFulfilled());
    }

    public function testFulfillConditionAutoTransitionsToReadyToCommit(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

    public function testAreAllConditionsFulfilledWithNoConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->assertFalse($contract->areAllConditionsFulfilled());
    }

    public function testAreAllConditionsFulfilledWithPendingConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $this->assertFalse($contract->areAllConditionsFulfilled());
    }

    public function testAreAllConditionsFulfilledWhenAllFulfilled(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $this->assertTrue($contract->areAllConditionsFulfilled());
    }

    public function testFailCondition(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $contract->failCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, 'Payment declined');

        $this->assertTrue($contract->getState()->equals(ContractState::failed()));
    }

    public function testCommitToOrderRequiresReadyToCommitState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Contract must be in READY_TO_COMMIT state to commit');

        $contract->commitToOrder('order123');
    }

    public function testCommitToOrder(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $contract->commitToOrder('order_123');

        $this->assertEquals('order_123', $contract->getOrderId());
        $this->assertTrue($contract->getState()->isCommitted());
    }

    public function testFulfillRequiresCommittedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Contract must be COMMITTED before fulfillment');

        $contract->fulfill();
    }

    public function testFulfill(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order_123');

        $contract->fulfill();

        $this->assertTrue($contract->getState()->isFulfilled());
        $this->assertNotNull($contract->getFulfilledAt());
    }

    public function testCancel(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->cancel('User cancelled');

        $this->assertEquals('cancelled', $contract->getStateValue());
    }

    public function testCancelTerminalStateThrowsException(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order_123');
        $contract->fulfill();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot cancel a terminal state contract');

        $contract->cancel();
    }

    public function testFail(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->fail('Payment failed');

        $this->assertEquals('failed', $contract->getStateValue());
    }

    public function testExpire(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->expire();

        $this->assertEquals('expired', $contract->getStateValue());
    }

    public function testIsExpiredReturnsFalseForTerminalState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order_123');
        $contract->fulfill();

        $this->assertFalse($contract->isExpired());
    }

    public function testSetProvider(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->setProvider('stripe', 'pi_123456', 'https://checkout.stripe.com');

        $this->assertEquals('stripe', $contract->getProvider());
        $this->assertEquals('pi_123456', $contract->getProviderOrderId());
        $this->assertEquals('https://checkout.stripe.com', $contract->getProviderRedirectUrl());
    }

    public function testToArrayAndFromArray(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot(), 'test_id');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->setProvider('stripe', 'pi_123');

        $array = $contract->toArray();

        $this->assertEquals('test_id', $array['id']);
        $this->assertEquals(1, $array['shopId']);
        $this->assertEquals('user123', $array['userId']);
        $this->assertNull($array['orderId']);
        $this->assertEquals('draft', $array['state']);
        $this->assertIsArray($array['basketSnapshot']);
        $this->assertCount(1, $array['conditions']);
        $this->assertEquals('stripe', $array['provider']);

        $restored = PaymentContract::fromArray($array);

        $this->assertEquals($contract->getId(), $restored->getId());
        $this->assertEquals($contract->getShopId(), $restored->getShopId());
        $this->assertEquals($contract->getUserId(), $restored->getUserId());
        $this->assertEquals($contract->getStateValue(), $restored->getStateValue());
        $this->assertCount(1, $restored->getConditions());
    }

    // ==========================================
    // AUTHORIZED STATE TESTS (Sprint 1)
    // ==========================================

    public function testAuthorizeTransitionsFromPendingToAuthorized(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $contract->authorize();

        $this->assertTrue($contract->getState()->isAuthorized());
        $this->assertEquals('authorized', $contract->getStateValue());
    }

    public function testAuthorizeThrowsExceptionForDraftContract(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only transition to AUTHORIZED from PENDING state');

        $contract->authorize();
    }

    public function testAuthorizeThrowsExceptionForReadyToCommitContract(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only transition to AUTHORIZED from PENDING state');

        $contract->authorize();
    }

    public function testCaptureAuthorizationTransitionsToReadyToCommit(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->authorize();

        $contract->captureAuthorization();

        $this->assertTrue($contract->getState()->isReadyToCommit());
        $this->assertEquals('ready_to_commit', $contract->getStateValue());
    }

    public function testCaptureAuthorizationThrowsExceptionForNonAuthorizedContract(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only capture authorization from AUTHORIZED state');

        $contract->captureAuthorization();
    }

    public function testCaptureAuthorizationThrowsExceptionForDraftContract(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only capture authorization from AUTHORIZED state');

        $contract->captureAuthorization();
    }

    public function testFullManualCaptureFlow(): void
    {
        // Test the complete flow: DRAFT -> NOT_FINISHED -> PENDING -> AUTHORIZED -> READY_TO_COMMIT -> COMMITTED -> FULFILLED
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        // DRAFT -> NOT_FINISHED (order created)
        $contract->transitionToNotFinished('order_123');
        $this->assertTrue($contract->getState()->isNotFinished());
        $this->assertEquals('order_123', $contract->getOrderId());

        // NOT_FINISHED -> PENDING
        $contract->transitionToPending();
        $this->assertTrue($contract->getState()->isPending());

        // Mark payment condition as fulfilled (provider authorized the payment)
        // Note: In manual capture mode, we fulfill the condition but then call authorize()
        // to indicate we're waiting for capture instead of auto-transitioning to READY_TO_COMMIT
        $conditions = $contract->getConditions();
        $conditions[0]->fulfill(['authId' => 'pi_123']);

        // PENDING -> AUTHORIZED (manual capture mode)
        $contract->authorize();
        $this->assertTrue($contract->getState()->isAuthorized());
        $this->assertTrue($contract->areAllConditionsFulfilled());

        // AUTHORIZED -> READY_TO_COMMIT (capture executed)
        $contract->captureAuthorization();
        $this->assertTrue($contract->getState()->isReadyToCommit());

        // READY_TO_COMMIT -> COMMITTED
        $contract->commitToOrder('order_123');
        $this->assertTrue($contract->getState()->isCommitted());

        // COMMITTED -> FULFILLED
        $contract->fulfill();
        $this->assertTrue($contract->getState()->isFulfilled());
    }

    public function testCancelFromAuthorizedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->authorize();

        $contract->cancel('Admin cancelled authorization');

        $this->assertEquals('cancelled', $contract->getStateValue());
    }

    public function testExpireFromAuthorizedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->authorize();

        $contract->expire();

        $this->assertEquals('expired', $contract->getStateValue());
    }

    public function testToArrayIncludesAuthorizedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot(), 'test_id');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->authorize();

        $array = $contract->toArray();

        $this->assertEquals('authorized', $array['state']);
    }

    public function testFromArrayRestoresAuthorizedState(): void
    {
        $data = [
            'id' => 'test_id',
            'shopId' => 1,
            'userId' => 'user123',
            'orderId' => null,
            'state' => 'authorized',
            'basketSnapshot' => [
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.03,
                'totalVat' => 15.97,
                'currency' => 'EUR',
                'capturedAt' => date('Y-m-d H:i:s'),
            ],
            'conditions' => [],
            'metadata' => [],
        ];

        $contract = PaymentContract::fromArray($data);

        $this->assertTrue($contract->getState()->isAuthorized());
        $this->assertEquals('authorized', $contract->getStateValue());
    }

    // ==========================================
    // Sprint 47: Fix 8 - State guards (STRP-99)
    // ==========================================

    public function testSetCapturedAmountInCommittedState(): void
    {
        $contract = $this->createFulfilledContract();

        // Restore to committed via fromArray
        $data = $contract->toArray();
        $data['state'] = 'committed';
        $committed = PaymentContract::fromArray($data);

        $committed->setCapturedAmount(100.0);

        $this->assertEquals(100.0, $committed->getCapturedAmount());
    }

    public function testSetCapturedAmountInFulfilledState(): void
    {
        $contract = $this->createFulfilledContract();

        $contract->setCapturedAmount(50.0);

        $this->assertEquals(50.0, $contract->getCapturedAmount());
    }

    public function testSetCapturedAmountInDraftStateThrows(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('COMMITTED or FULFILLED');

        $contract->setCapturedAmount(100.0);
    }

    public function testSetCapturedAmountInPendingStateThrows(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('COMMITTED or FULFILLED');

        $contract->setCapturedAmount(100.0);
    }

    public function testSetCapturedAmountRejectsNegative(): void
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $contract->setCapturedAmount(-1.0);
    }

    public function testSetCapturedAmountRejectsZero(): void
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $contract->setCapturedAmount(0.0);
    }

    public function testSetCapturedAmountRejectsInfinity(): void
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $contract->setCapturedAmount(INF);
    }

    public function testAddRefundedAmountInFulfilledState(): void
    {
        $contract = $this->createFulfilledContract();
        $contract->setCapturedAmount(100.0);

        $contract->addRefundedAmount(50.0);

        $this->assertEquals(50.0, $contract->getRefundedAmount());
    }

    public function testAddRefundedAmountAccumulates(): void
    {
        $contract = $this->createFulfilledContract();
        $contract->setCapturedAmount(100.0);

        $contract->addRefundedAmount(30.0);
        $contract->addRefundedAmount(20.0);

        $this->assertEquals(50.0, $contract->getRefundedAmount());
    }

    public function testAddRefundedAmountInDraftStateThrows(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('FULFILLED');

        $contract->addRefundedAmount(10.0);
    }

    public function testAddRefundedAmountInCommittedStateThrows(): void
    {
        $data = $this->createFulfilledContract()->toArray();
        $data['state'] = 'committed';
        $committed = PaymentContract::fromArray($data);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('FULFILLED');

        $committed->addRefundedAmount(10.0);
    }

    public function testAddRefundedAmountRejectsNegative(): void
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $contract->addRefundedAmount(-5.0);
    }

    public function testAddRefundedAmountRejectsZero(): void
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $contract->addRefundedAmount(0.0);
    }

    public function testAddRefundedAmountRejectsNan(): void
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $contract->addRefundedAmount(NAN);
    }

    private function createFulfilledContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order_123');
        $contract->fulfill();
        return $contract;
    }
}
