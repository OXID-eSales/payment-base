<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\OrderCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryOrderRepository;
use PHPUnit\Framework\TestCase;

class OrderCreationHandlerTest extends TestCase
{
    private ContractRepository $contractRepository;
    private InMemoryOrderRepository $orderRepository;
    private EventDispatcher $dispatcher;
    private OrderCreationHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = new ContractRepository();
        $this->orderRepository = new InMemoryOrderRepository();
        $this->dispatcher = new EventDispatcher();
        $this->handler = new OrderCreationHandler(
            $this->contractRepository,
            $this->orderRepository,
            $this->dispatcher
        );
    }

    private function createReadyContract(): PaymentContract
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
        $contract->transitionToNotFinished('order_temp');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);
        $contract->setProvider('stripe', 'pi_456');

        return $contract;
    }

    public function testCreatesOrderFromContract(): void
    {
        $contract = $this->createReadyContract();
        $this->contractRepository->save($contract);

        $context = new EventContext([
            'userId' => 'user123',
            'sessionId' => 'sess_789',
        ]);

        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->handler->handle($event);

        $orders = $this->orderRepository->findAll();

        $this->assertCount(1, $orders);
        $this->assertEquals('user123', $orders[0]->getUserId());
        $this->assertEquals(100.0, $orders[0]->getTotalGross());
        $this->assertEquals('EUR', $orders[0]->getCurrency());
    }

    public function testAssignsOrderNumber(): void
    {
        $contract = $this->createReadyContract();
        $this->contractRepository->save($contract);

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->handler->handle($event);

        $orders = $this->orderRepository->findAll();

        $this->assertCount(1, $orders);
        $this->assertNotEmpty($orders[0]->getOrderNumber());
        $this->assertMatchesRegularExpression('/^\d+$/', $orders[0]->getOrderNumber());
    }

    public function testCommitsContractToOrder(): void
    {
        $contract = $this->createReadyContract();
        $this->contractRepository->save($contract);

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->handler->handle($event);

        $updated = $this->contractRepository->findById($contract->getId());

        $this->assertTrue($updated->getState()->isCommitted());
        $this->assertNotNull($updated->getOrderId());
    }

    public function testSavesOrder(): void
    {
        $contract = $this->createReadyContract();
        $this->contractRepository->save($contract);

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->handler->handle($event);

        $orders = $this->orderRepository->findAll();
        $this->assertCount(1, $orders);

        $savedOrder = $this->orderRepository->findById($orders[0]->getId());
        $this->assertNotNull($savedOrder);
        $this->assertEquals($orders[0]->getId(), $savedOrder->getId());
    }

    public function testEmitsContractCommittedEvent(): void
    {
        $eventEmitted = false;
        $emittedContract = null;

        $this->dispatcher->addListener(
            ContractCommittedEvent::class,
            function (ContractCommittedEvent $event) use (&$eventEmitted, &$emittedContract) {
                $eventEmitted = true;
                $emittedContract = $event->getContract();
            }
        );

        $contract = $this->createReadyContract();
        $this->contractRepository->save($contract);

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->handler->handle($event);

        $this->assertTrue($eventEmitted);
        $this->assertNotNull($emittedContract);
        $this->assertTrue($emittedContract->getState()->isCommitted());
    }

    public function testThrowsExceptionWhenNotReadyToCommit(): void
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

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot create order: contract is not ready to commit');

        $this->handler->handle($event);
    }

    public function testThrowsExceptionWhenConditionsNotFulfilled(): void
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
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot create order: contract is not ready to commit');

        $this->handler->handle($event);
    }
}
