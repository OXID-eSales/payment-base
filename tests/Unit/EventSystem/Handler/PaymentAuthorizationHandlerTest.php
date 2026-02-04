<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\PaymentAuthorizationHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentAuthorizationHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $repository;
    private EventDispatcher $dispatcher;
    private PaymentAuthorizationHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->dispatcher = new EventDispatcher();
        $this->handler = new PaymentAuthorizationHandler(
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
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        return $contract;
    }

    public function testFulfillsPaymentAuthorizedCondition(): void
    {
        $contract = $this->createTestContract();

        $this->repository->expects($this->once())
            ->method('save')
            ->with($contract);

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $conditions = $contract->getConditions();
        $this->assertTrue($conditions[0]->isFulfilled());
    }

    public function testSetsProviderOrderId(): void
    {
        $contract = $this->createTestContract();

        $this->repository->expects($this->once())
            ->method('save');

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
            'providerName' => 'stripe',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $this->assertEquals('pi_456', $contract->getProviderOrderId());
    }

    public function testEmitsReadyToCommitWhenAllFulfilled(): void
    {
        $eventEmitted = false;

        $this->dispatcher->addListener(
            ContractReadyToCommitEvent::class,
            function () use (&$eventEmitted) {
                $eventEmitted = true;
            }
        );

        $contract = $this->createTestContract();

        $this->repository->expects($this->once())
            ->method('save');

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
            'providerName' => 'stripe',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $this->assertTrue($eventEmitted);
    }

    public function testDoesNotEmitWhenOtherConditionsPending(): void
    {
        $eventEmitted = false;

        $this->dispatcher->addListener(
            ContractReadyToCommitEvent::class,
            function () use (&$eventEmitted) {
                $eventEmitted = true;
            }
        );

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
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $this->repository->expects($this->once())
            ->method('save');

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $this->assertFalse($eventEmitted);
    }
}
