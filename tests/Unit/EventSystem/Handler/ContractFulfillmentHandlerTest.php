<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\ContractFulfillmentHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Order\Order;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryOrderRepository;
use PHPUnit\Framework\TestCase;

class ContractFulfillmentHandlerTest extends TestCase
{
    private ContractRepository $contractRepository;
    private InMemoryOrderRepository $orderRepository;
    private EventDispatcher $dispatcher;
    private ContractFulfillmentHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = new ContractRepository();
        $this->orderRepository = new InMemoryOrderRepository();
        $this->dispatcher = new EventDispatcher();
        $this->handler = new ContractFulfillmentHandler(
            $this->contractRepository,
            $this->orderRepository,
            $this->dispatcher
        );
    }

    private function createCommittedContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [
                ['productId' => 'prod1', 'quantity' => 2, 'price' => 50.0],
            ],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('1');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_123',
        ]);
        $contract->setProvider('stripe', 'pi_456');

        $order = new Order(
            1,
            '1001',
            'user123',
            100.0,
            84.03,
            15.97,
            'EUR',
            [],
            $contract->getId()
        );
        $this->orderRepository->save($order);

        $contract->commitToOrder('1');

        return $contract;
    }

    public function testFulfillsContract(): void
    {
        $contract = $this->createCommittedContract();
        $this->contractRepository->save($contract);

        $context = new EventContext([
            'contractId' => $contract->getId(),
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            'stripe',
            'payment_intent.succeeded',
            ['id' => 'pi_456', 'status' => 'succeeded'],
            'sig_123'
        );

        $this->handler->handle($event);

        $updated = $this->contractRepository->findById($contract->getId());

        $this->assertTrue($updated->getState()->isFulfilled());
        $this->assertNotNull($updated->getFulfilledAt());
    }

    public function testUpdatesOrderStatus(): void
    {
        $contract = $this->createCommittedContract();
        $this->contractRepository->save($contract);

        $context = new EventContext([
            'contractId' => $contract->getId(),
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            'stripe',
            'payment_intent.succeeded',
            ['id' => 'pi_456', 'status' => 'succeeded'],
            'sig_123'
        );

        $this->handler->handle($event);

        $order = $this->orderRepository->findById(1);

        $this->assertEquals('completed', $order->getStatus());
    }

    public function testEmitsFulfilledEvent(): void
    {
        $eventEmitted = false;
        $emittedContract = null;

        $this->dispatcher->addListener(
            ContractFulfilledEvent::class,
            function (ContractFulfilledEvent $event) use (&$eventEmitted, &$emittedContract) {
                $eventEmitted = true;
                $emittedContract = $event->getContract();
            }
        );

        $contract = $this->createCommittedContract();
        $this->contractRepository->save($contract);

        $context = new EventContext([
            'contractId' => $contract->getId(),
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            'stripe',
            'payment_intent.succeeded',
            ['id' => 'pi_456', 'status' => 'succeeded'],
            'sig_123'
        );

        $this->handler->handle($event);

        $this->assertTrue($eventEmitted);
        $this->assertNotNull($emittedContract);
        $this->assertTrue($emittedContract->getState()->isFulfilled());
    }

    public function testOnlyFulfillsCommittedContract(): void
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
        $this->contractRepository->save($contract);

        $context = new EventContext([
            'contractId' => $contract->getId(),
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            'stripe',
            'payment_intent.succeeded',
            ['id' => 'pi_456', 'status' => 'succeeded'],
            'sig_123'
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Contract must be COMMITTED before fulfillment');

        $this->handler->handle($event);
    }

    public function testIgnoresNonCaptureWebhooks(): void
    {
        $contract = $this->createCommittedContract();
        $this->contractRepository->save($contract);

        $context = new EventContext([
            'contractId' => $contract->getId(),
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            'stripe',
            'customer.created',
            ['id' => 'cus_123'],
            'sig_123'
        );

        $this->handler->handle($event);

        $updated = $this->contractRepository->findById($contract->getId());

        $this->assertFalse($updated->getState()->isFulfilled());
        $this->assertTrue($updated->getState()->isCommitted());
    }
}
