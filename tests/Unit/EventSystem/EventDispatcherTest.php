<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;

class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
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

        return new PaymentContract(1, 'user123', $snapshot);
    }

    public function testDispatchEventToRegisteredListener(): void
    {
        $listenerCalled = false;
        $receivedEvent = null;

        $listener = function (EventInterface $event) use (&$listenerCalled, &$receivedEvent) {
            $listenerCalled = true;
            $receivedEvent = $event;
        };

        $this->dispatcher->addListener(ContractCreatedEvent::class, $listener);

        $contract = $this->createTestContract();
        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->dispatcher->dispatch($event);

        $this->assertTrue($listenerCalled);
        $this->assertSame($event, $receivedEvent);
    }

    public function testDispatchEventToMultipleListeners(): void
    {
        $callCount = 0;

        $listener1 = function () use (&$callCount) {
            $callCount++;
        };

        $listener2 = function () use (&$callCount) {
            $callCount++;
        };

        $this->dispatcher->addListener(ContractCreatedEvent::class, $listener1);
        $this->dispatcher->addListener(ContractCreatedEvent::class, $listener2);

        $contract = $this->createTestContract();
        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->dispatcher->dispatch($event);

        $this->assertEquals(2, $callCount);
    }

    public function testListenersExecutedInPriorityOrder(): void
    {
        $executionOrder = [];

        $listener1 = function () use (&$executionOrder) {
            $executionOrder[] = 'low';
        };

        $listener2 = function () use (&$executionOrder) {
            $executionOrder[] = 'high';
        };

        $this->dispatcher->addListener(ContractCreatedEvent::class, $listener1, 10);
        $this->dispatcher->addListener(ContractCreatedEvent::class, $listener2, 100);

        $contract = $this->createTestContract();
        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->dispatcher->dispatch($event);

        $this->assertEquals(['high', 'low'], $executionOrder);
    }

    public function testStoppableEventStopsExecution(): void
    {
        $callCount = 0;

        $listener1 = function (TestStoppableEvent $event) use (&$callCount) {
            $callCount++;
            $event->stopPropagation();
        };

        $listener2 = function () use (&$callCount) {
            $callCount++;
        };

        $this->dispatcher->addListener(TestStoppableEvent::class, $listener1, 100);
        $this->dispatcher->addListener(TestStoppableEvent::class, $listener2, 10);

        $event = new TestStoppableEvent();
        $this->dispatcher->dispatch($event);

        $this->assertEquals(1, $callCount);
    }

    public function testRemoveListener(): void
    {
        $listenerCalled = false;

        $listener = function () use (&$listenerCalled) {
            $listenerCalled = true;
        };

        $this->dispatcher->addListener(ContractCreatedEvent::class, $listener);
        $this->dispatcher->removeListener(ContractCreatedEvent::class, $listener);

        $contract = $this->createTestContract();
        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->dispatcher->dispatch($event);

        $this->assertFalse($listenerCalled);
    }

    public function testNoListenersRegistered(): void
    {
        $contract = $this->createTestContract();
        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $result = $this->dispatcher->dispatch($event);

        $this->assertSame($event, $result);
    }

    public function testDispatchReturnsEvent(): void
    {
        $contract = $this->createTestContract();
        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $result = $this->dispatcher->dispatch($event);

        $this->assertSame($event, $result);
    }
}

class TestStoppableEvent implements EventInterface
{
    private bool $propagationStopped = false;

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
