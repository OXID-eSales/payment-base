<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Adapter\Response\OrderResponse;
use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\EarlyOrderCreationHandler;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EarlyOrderCreationHandler (STRP-74).
 */
class EarlyOrderCreationHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $shopOrderService;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private EarlyOrderCreationHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->shopOrderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->handler = new EarlyOrderCreationHandler(
            $this->contractRepository,
            $this->shopOrderService,
            $this->eventDispatcher
        );
    }

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

    private function createDraftContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot(), 'contract_123');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        return $contract;
    }

    private function createOrderResponse(string $orderId = '123'): OrderResponse
    {
        return new OrderResponse(
            orderId: $orderId,
            orderNumber: 1001,
            userId: 'user123',
            totalAmount: 100.0,
            currency: 'EUR',
            status: 'not_finished',
            paymentId: 'oxidstripe',
            paymentTransactionId: null,
            createdAt: new DateTimeImmutable(),
            metadata: [],
            shopData: []
        );
    }

    // ==========================================
    // HANDLER BASIC TESTS
    // ==========================================

    public function testGetHandledEventClassReturnsCorrectClass(): void
    {
        $this->assertEquals(
            ContractDraftCompletedEvent::class,
            EarlyOrderCreationHandler::getHandledEventClass()
        );
    }

    public function testHandlerCreatesOrderOnContractDraftCompletedEvent(): void
    {
        $contract = $this->createDraftContract();
        $context = new EventContext(['paymentId' => 'oe_payments_stripe_wallet']);
        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->shopOrderService
            ->expects($this->once())
            ->method('createOrder')
            ->willReturn($this->createOrderResponse('456'));

        // Contract is saved twice: once after NOT_FINISHED, once after PENDING
        $this->contractRepository
            ->expects($this->exactly(2))
            ->method('save');

        $this->handler->handle($event);

        // Contract should be in PENDING state after handler completes
        $this->assertTrue($contract->getState()->isPending());
        $this->assertEquals('456', $contract->getOrderId());
    }

    public function testHandlerDispatchesEvents(): void
    {
        $contract = $this->createDraftContract();
        $context = new EventContext(['paymentId' => 'oe_payments_stripe_wallet']);
        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->shopOrderService
            ->method('createOrder')
            ->willReturn($this->createOrderResponse('789'));

        // Handler dispatches 2 events: ContractTransitionedToPendingEvent, then OrderCreatedEvent
        $dispatchedEvents = [];
        $this->eventDispatcher
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
                return $event;
            });

        $this->handler->handle($event);

        $this->assertCount(2, $dispatchedEvents);
        $this->assertInstanceOf(ContractTransitionedToPendingEvent::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(OrderCreatedEvent::class, $dispatchedEvents[1]);
        $this->assertEquals('789', $dispatchedEvents[1]->getOrderId());
        $this->assertEquals('contract_123', $dispatchedEvents[1]->getContractId());
    }

    public function testHandlerDoesNothingIfContractNotInDraftState(): void
    {
        $contract = $this->createDraftContract();
        $contract->transitionToNotFinished('existing_order');
        $contract->transitionToPending();
        $context = new EventContext();
        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->shopOrderService
            ->expects($this->never())
            ->method('createOrder');

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $this->handler->handle($event);
    }

    public function testHandlerUsesPaymentIdFromContext(): void
    {
        $contract = $this->createDraftContract();
        $context = new EventContext(['paymentId' => 'oe_payments_stripe_wallet']);
        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->shopOrderService
            ->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(function ($request) {
                $this->assertInstanceOf(\OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest::class, $request);
                $this->assertEquals('oe_payments_stripe_wallet', $request->paymentId);
                return true;
            }))
            ->willReturn($this->createOrderResponse('555'));

        $this->handler->handle($event);
    }

    public function testHandlerFallsBackToUnknownWhenNoPaymentIdInContext(): void
    {
        $contract = $this->createDraftContract();
        $context = new EventContext();
        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->shopOrderService
            ->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(function ($request) {
                $this->assertInstanceOf(\OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest::class, $request);
                $this->assertEquals('unknown_payment', $request->paymentId);
                return true;
            }))
            ->willReturn($this->createOrderResponse('666'));

        $this->handler->handle($event);
    }

    public function testHandlerDoesNothingForWrongEventType(): void
    {
        $wrongEvent = new \stdClass();

        $this->shopOrderService
            ->expects($this->never())
            ->method('createOrder');

        $this->handler->handle($wrongEvent);
    }

    // ==========================================
    // CONTRACT STATE TESTS
    // ==========================================

    public function testHandlerLinksContractToOrder(): void
    {
        $contract = $this->createDraftContract();
        $context = new EventContext(['paymentId' => 'oe_payments_stripe_wallet']);
        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->shopOrderService
            ->method('createOrder')
            ->willReturn($this->createOrderResponse('999'));

        $this->handler->handle($event);

        $this->assertEquals('999', $contract->getOrderId());
    }

    public function testHandlerTransitionsContractToPending(): void
    {
        $contract = $this->createDraftContract();
        $this->assertTrue($contract->getState()->isDraft());

        $context = new EventContext(['paymentId' => 'oe_payments_stripe_wallet']);
        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->shopOrderService
            ->method('createOrder')
            ->willReturn($this->createOrderResponse('111'));

        $this->handler->handle($event);

        $this->assertFalse($contract->getState()->isDraft());
        $this->assertFalse($contract->getState()->isNotFinished());
        $this->assertTrue($contract->getState()->isPending());
    }
}
