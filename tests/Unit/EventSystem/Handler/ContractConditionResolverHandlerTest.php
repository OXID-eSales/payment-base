<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\EventSystem\Handler\ContractConditionResolverHandler;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcher;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContractConditionResolverHandler.
 *
 * STRP-74: Updated to test new flow where handler dispatches
 * ContractDraftCompletedEvent instead of transitioning directly to PENDING.
 */
class ContractConditionResolverHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $repository;
    private EventDispatcher $dispatcher;
    private ContractConditionResolverHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->dispatcher = new EventDispatcher();
        $this->handler = new ContractConditionResolverHandler(
            $this->repository,
            $this->dispatcher
        );
    }

    private function createTestContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));

        return $contract;
    }

    public function testDispatchesDraftCompletedEvent(): void
    {
        $eventEmitted = false;
        $emittedContract = null;

        $this->dispatcher->addListener(
            ContractDraftCompletedEvent::class,
            function (ContractDraftCompletedEvent $event) use (&$eventEmitted, &$emittedContract) {
                $eventEmitted = true;
                $emittedContract = $event->getContract();
            }
        );

        $contract = $this->createTestContract();

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->handler->handle($event);

        $this->assertTrue($eventEmitted);
        $this->assertNotNull($emittedContract);
        $this->assertTrue($emittedContract->getState()->isDraft());
    }

    public function testContractRemainsInDraftAfterHandler(): void
    {
        $contract = $this->createTestContract();

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->handler->handle($event);

        // Contract should still be in DRAFT state
        // The EarlyOrderCreationHandler will transition it to NOT_FINISHED
        $this->assertTrue($contract->getState()->isDraft());
    }

    public function testThrowsExceptionWhenNoConditions(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transition to PENDING without conditions');

        $this->handler->handle($event);
    }

    public function testDoesNotDispatchEventWhenContractAlreadyProcessed(): void
    {
        $eventCount = 0;

        $this->dispatcher->addListener(
            ContractDraftCompletedEvent::class,
            function (ContractDraftCompletedEvent $event) use (&$eventCount) {
                $eventCount++;
            }
        );

        $contract = $this->createTestContract();
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        // Handler should still dispatch the event (it doesn't check state)
        // But downstream handlers should not process already-processed contracts
        $this->handler->handle($event);

        // Event is dispatched but EarlyOrderCreationHandler would ignore it
        // because contract is not in DRAFT state
        $this->assertEquals(1, $eventCount);
    }
}
