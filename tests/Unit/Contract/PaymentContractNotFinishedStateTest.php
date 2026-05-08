<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use DomainException;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NOT_FINISHED state transitions (STRP-74).
 *
 * New flow: DRAFT -> NOT_FINISHED -> PENDING -> READY_TO_COMMIT -> COMMITTED -> FULFILLED
 */
class PaymentContractNotFinishedStateTest extends TestCase
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

    // ==========================================
    // DRAFT -> NOT_FINISHED TRANSITION TESTS
    // ==========================================

    public function testTransitionToNotFinishedFromDraft(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        $contract->transitionToNotFinished('order_123');

        $this->assertTrue($contract->getState()->isNotFinished());
        $this->assertEquals('not_finished', $contract->getStateValue());
        $this->assertEquals('order_123', $contract->getOrderId());
    }

    public function testTransitionToNotFinishedRequiresOrderId(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Order ID is required for NOT_FINISHED transition');

        $contract->transitionToNotFinished('');
    }

    public function testTransitionToNotFinishedRequiresConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot transition to NOT_FINISHED without conditions');

        $contract->transitionToNotFinished('order_123');
    }

    public function testTransitionToNotFinishedOnlyFromDraft(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Can only transition to NOT_FINISHED from DRAFT state');

        $contract->transitionToNotFinished('order_456');
    }

    // ==========================================
    // NOT_FINISHED -> PENDING TRANSITION TESTS
    // ==========================================

    public function testTransitionToPendingFromNotFinished(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $contract->transitionToPending();

        $this->assertTrue($contract->getState()->isPending());
        $this->assertEquals('order_123', $contract->getOrderId());
    }

    public function testTransitionToPendingFromDraftNoLongerAllowed(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Can only transition to PENDING from NOT_FINISHED state');

        $contract->transitionToPending();
    }

    // ==========================================
    // ADD CONDITIONS BEHAVIOR TESTS
    // ==========================================

    public function testCannotAddConditionsAfterNotFinished(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot add conditions after DRAFT state');

        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));
    }

    // ==========================================
    // FULL FLOW TESTS
    // ==========================================

    public function testFullFlowWithNotFinishedState(): void
    {
        // Test complete flow: DRAFT -> NOT_FINISHED -> PENDING -> READY_TO_COMMIT -> COMMITTED -> FULFILLED
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        // DRAFT state
        $this->assertTrue($contract->getState()->isDraft());
        $this->assertNull($contract->getOrderId());

        // DRAFT -> NOT_FINISHED (order created)
        $contract->transitionToNotFinished('order_123');
        $this->assertTrue($contract->getState()->isNotFinished());
        $this->assertEquals('order_123', $contract->getOrderId());

        // NOT_FINISHED -> PENDING
        $contract->transitionToPending();
        $this->assertTrue($contract->getState()->isPending());

        // Fulfill condition -> auto-transition to READY_TO_COMMIT
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => 'pi_123']);
        $this->assertTrue($contract->getState()->isReadyToCommit());

        // READY_TO_COMMIT -> COMMITTED (order already exists, just update link)
        $contract->commitToOrder('order_123');
        $this->assertTrue($contract->getState()->isCommitted());

        // COMMITTED -> FULFILLED
        $contract->fulfill();
        $this->assertTrue($contract->getState()->isFulfilled());
    }

    // ==========================================
    // CANCELLATION TESTS
    // ==========================================

    public function testCancelFromNotFinishedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $contract->cancel('User cancelled');

        $this->assertEquals('cancelled', $contract->getStateValue());
        $this->assertEquals('order_123', $contract->getOrderId());
    }

    public function testFailFromNotFinishedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $contract->fail('Payment declined');

        $this->assertEquals('failed', $contract->getStateValue());
    }

    // ==========================================
    // SERIALIZATION TESTS
    // ==========================================

    public function testToArrayIncludesNotFinishedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot(), 'test_id');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $array = $contract->toArray();

        $this->assertEquals('not_finished', $array['state']);
        $this->assertEquals('order_123', $array['orderId']);
    }

    public function testFromArrayRestoresNotFinishedState(): void
    {
        $data = [
            'id' => 'test_id',
            'shopId' => 1,
            'userId' => 'user123',
            'orderId' => 'order_123',
            'state' => 'not_finished',
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

        $this->assertTrue($contract->getState()->isNotFinished());
        $this->assertEquals('not_finished', $contract->getStateValue());
        $this->assertEquals('order_123', $contract->getOrderId());
    }

    // ==========================================
    // isInState TESTS
    // ==========================================

    public function testIsInStateForNotFinished(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');

        $this->assertTrue($contract->isInState('not_finished'));
        $this->assertFalse($contract->isInState('draft'));
        $this->assertFalse($contract->isInState('pending'));
    }
}
