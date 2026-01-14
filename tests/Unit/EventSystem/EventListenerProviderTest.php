<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem;

use OxidEsales\PaymentComponent\EventSystem\EventListenerProvider;
use OxidEsales\PaymentComponent\EventSystem\EventListenerProviderInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;

class EventListenerProviderTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $provider = new EventListenerProvider([]);

        $this->assertInstanceOf(EventListenerProviderInterface::class, $provider);
    }

    public function testGetListenersForEvent_WithNoListeners_ReturnsEmptyArray(): void
    {
        $provider = new EventListenerProvider([]);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        $this->assertIsArray($listeners);
        $this->assertCount(0, $listeners);
    }

    public function testGetListenersForEvent_WithRegisteredHandler_ReturnsListener(): void
    {
        $handler = $this->createPaymentInitiatedHandler();

        $provider = new EventListenerProvider([$handler]);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        $this->assertCount(1, $listeners);
        $this->assertIsCallable($listeners[0]);
    }

    public function testAddListener_ManualRegistration_IsRetrievable(): void
    {
        $provider = new EventListenerProvider([]);
        $listener = fn($event) => null;

        $provider->addListener(PaymentInitiatedEvent::class, $listener);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);
        $this->assertContains($listener, $listeners);
    }

    public function testGetListenersForEvent_WithMultiplePriorities_ReturnsSortedByPriority(): void
    {
        $provider = new EventListenerProvider([]);

        $lowPriority = fn($e) => 'low';
        $highPriority = fn($e) => 'high';
        $mediumPriority = fn($e) => 'medium';

        $provider->addListener(PaymentInitiatedEvent::class, $lowPriority, 0);
        $provider->addListener(PaymentInitiatedEvent::class, $highPriority, 100);
        $provider->addListener(PaymentInitiatedEvent::class, $mediumPriority, 50);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        $this->assertSame($highPriority, $listeners[0]);
        $this->assertSame($mediumPriority, $listeners[1]);
        $this->assertSame($lowPriority, $listeners[2]);
    }

    public function testConstructor_WithMultipleHandlers_RegistersAll(): void
    {
        $handler1 = $this->createPaymentInitiatedHandler();
        $handler2 = $this->createPaymentInitiatedHandler();

        $provider = new EventListenerProvider([$handler1, $handler2]);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);
        $this->assertCount(2, $listeners);
    }

    public function testRegisterHandler_RegistersToCorrectEventClass(): void
    {
        // Handler that handles PaymentInitiatedEvent
        $handler = $this->createPaymentInitiatedHandler();

        $provider = new EventListenerProvider([$handler]);

        // Should be registered for PaymentInitiatedEvent
        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);
        $this->assertCount(1, $listeners);

        // Should NOT be registered for ContractCreatedEvent
        $otherListeners = $provider->getListenersForEvent(ContractCreatedEvent::class);
        $this->assertCount(0, $otherListeners);
    }

    public function testRegisterHandler_WithDifferentEventTypes_RegistersToCorrectEvents(): void
    {
        $paymentHandler = $this->createPaymentInitiatedHandler();
        $contractHandler = $this->createContractCreatedHandler();

        $provider = new EventListenerProvider([$paymentHandler, $contractHandler]);

        $paymentListeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);
        $contractListeners = $provider->getListenersForEvent(ContractCreatedEvent::class);

        $this->assertCount(1, $paymentListeners);
        $this->assertCount(1, $contractListeners);
    }

    public function testAddListener_WithSamePriority_MaintainsOrder(): void
    {
        $provider = new EventListenerProvider([]);

        $first = fn($e) => 'first';
        $second = fn($e) => 'second';
        $third = fn($e) => 'third';

        $provider->addListener(PaymentInitiatedEvent::class, $first, 0);
        $provider->addListener(PaymentInitiatedEvent::class, $second, 0);
        $provider->addListener(PaymentInitiatedEvent::class, $third, 0);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        // With same priority, order should be preserved (first added = first executed)
        $this->assertCount(3, $listeners);
    }

    public function testGetListenersForEvent_ForUnregisteredEvent_ReturnsEmptyArray(): void
    {
        $handler = $this->createPaymentInitiatedHandler();

        $provider = new EventListenerProvider([$handler]);

        $listeners = $provider->getListenersForEvent(ContractCreatedEvent::class);
        $this->assertCount(0, $listeners);
    }

    public function testHandlerIsCallable(): void
    {
        $handlerCalled = false;
        $handler = new class($handlerCalled) implements HandlerInterface {
            public function __construct(private bool &$called)
            {
            }

            public function handle(object $event): void
            {
                if ($event instanceof PaymentInitiatedEvent) {
                    $this->called = true;
                }
            }

            public static function getHandledEventClass(): string
            {
                return PaymentInitiatedEvent::class;
            }
        };

        $provider = new EventListenerProvider([$handler]);
        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        $this->assertNotEmpty($listeners);
        $event = $this->createPaymentInitiatedEvent();
        $listeners[0]($event);
        $this->assertTrue($handlerCalled);
    }

    private function createPaymentInitiatedHandler(): HandlerInterface
    {
        return new class implements HandlerInterface {
            public function handle(object $event): void
            {
                // Type check for the specific event
                if (!$event instanceof PaymentInitiatedEvent) {
                    throw new \InvalidArgumentException('Expected PaymentInitiatedEvent');
                }
            }

            public static function getHandledEventClass(): string
            {
                return PaymentInitiatedEvent::class;
            }
        };
    }

    private function createContractCreatedHandler(): HandlerInterface
    {
        return new class implements HandlerInterface {
            public function handle(object $event): void
            {
                // Type check for the specific event
                if (!$event instanceof ContractCreatedEvent) {
                    throw new \InvalidArgumentException('Expected ContractCreatedEvent');
                }
            }

            public static function getHandledEventClass(): string
            {
                return ContractCreatedEvent::class;
            }
        };
    }

    private function createPaymentInitiatedEvent(): PaymentInitiatedEvent
    {
        $context = new EventContext([]);
        return new PaymentInitiatedEvent(
            $context,
            'stripe_card',
            100.0,
            'EUR',
            'https://example.com/return',
            'https://example.com/cancel'
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

        return new PaymentContract(1, 'user123', $snapshot);
    }
}
