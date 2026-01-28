<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\GenericContractCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Service\ContractService;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

class ContractCreationHandlerTest extends TestCase
{
    private ContractRepository $repository;
    private EventDispatcher $dispatcher;
    private ContractService $service;
    private GenericContractCreationHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new ContractRepository();
        $this->dispatcher = new EventDispatcher();
        $this->service = new ContractService($this->repository);
        $this->handler = new GenericContractCreationHandler(
            $this->service,
            $this->dispatcher
        );
    }

    private function createMockBasket(): object
    {
        $basket = new \stdClass();
        $basket->totalGross = 100.0;
        $basket->totalNet = 84.03;
        $basket->totalVat = 15.97;
        $basket->currency = 'EUR';
        return $basket;
    }

    public function testHandleCreatesContract(): void
    {
        $basket = $this->createMockBasket();
        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            100.0,
            'EUR',
            'http://return',
            'http://cancel'
        );

        $this->handler->handle($event);

        $contract = $event->getContext()->getContract();

        $this->assertNotNull($contract);
        $this->assertEquals('user123', $contract->getUserId());
        $this->assertTrue($contract->getState()->isDraft());
    }

    public function testHandleAddsDefaultConditions(): void
    {
        $basket = $this->createMockBasket();
        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            100.0,
            'EUR',
            'http://return',
            'http://cancel'
        );

        $this->handler->handle($event);

        $contract = $event->getContext()->getContract();
        $this->assertNotNull($contract);
        $conditions = $contract->getConditions();

        $this->assertCount(2, $conditions);
        $this->assertEquals(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $conditions[0]->getType());
        $this->assertEquals(ContractCondition::TYPE_FRAUD_CHECK, $conditions[1]->getType());
    }

    public function testHandleAddsCustomConditions(): void
    {
        $basket = $this->createMockBasket();
        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => [
                ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                ContractCondition::TYPE_COMPLIANCE_CHECK,
            ],
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            100.0,
            'EUR',
            'http://return',
            'http://cancel'
        );

        $this->handler->handle($event);

        $contract = $event->getContext()->getContract();
        $this->assertNotNull($contract);
        $conditions = $contract->getConditions();

        $this->assertCount(2, $conditions);
        $this->assertEquals(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $conditions[0]->getType());
        $this->assertEquals(ContractCondition::TYPE_COMPLIANCE_CHECK, $conditions[1]->getType());
    }

    public function testHandleSavesContract(): void
    {
        $basket = $this->createMockBasket();
        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            100.0,
            'EUR',
            'http://return',
            'http://cancel'
        );

        $this->handler->handle($event);

        $contract = $event->getContext()->getContract();
        $this->assertNotNull($contract);
        $found = $this->repository->findById($contract->getId());

        $this->assertNotNull($found);
        $this->assertEquals($contract->getId(), $found->getId());
    }

    public function testHandleEmitsContractCreatedEvent(): void
    {
        $eventEmitted = false;
        $emittedContract = null;

        $this->dispatcher->addListener(
            ContractCreatedEvent::class,
            function (ContractCreatedEvent $event) use (&$eventEmitted, &$emittedContract) {
                $eventEmitted = true;
                $emittedContract = $event->getContract();
            }
        );

        $basket = $this->createMockBasket();
        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            100.0,
            'EUR',
            'http://return',
            'http://cancel'
        );

        $this->handler->handle($event);

        $this->assertTrue($eventEmitted);
        $this->assertNotNull($emittedContract);
        $this->assertEquals('user123', $emittedContract->getUserId());
    }

    public function testHandleThrowsExceptionWhenBasketEmpty(): void
    {
        $context = new EventContext([
            'userId' => 'user123',
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            100.0,
            'EUR',
            'http://return',
            'http://cancel'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Basket is required');

        $this->handler->handle($event);
    }

    public function testHandleThrowsExceptionWhenUserIdMissing(): void
    {
        $basket = $this->createMockBasket();
        $context = new EventContext([
            'basket' => $basket,
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            100.0,
            'EUR',
            'http://return',
            'http://cancel'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID is required');

        $this->handler->handle($event);
    }
}
