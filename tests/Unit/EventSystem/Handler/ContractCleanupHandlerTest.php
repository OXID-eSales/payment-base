<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\EventSystem\Handler\ContractCleanupHandler;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractExpiredEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContractCleanupHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $repository;
    private ContractCleanupHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->handler = new ContractCleanupHandler($this->repository);
    }

    private function createPendingContract(): PaymentContract
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
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        return $contract;
    }

    public function testCancelsContractOnCancelledEvent(): void
    {
        $contract = $this->createPendingContract();
        $contract->cancel('User requested cancellation');

        $this->repository->expects($this->once())
            ->method('save')
            ->with($contract);

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractCancelledEvent($contract, $context, 'User requested cancellation');

        $this->handler->handle($event);

        $this->assertTrue($contract->getState()->isCancelled());
    }

    public function testExpiresContractOnExpiredEvent(): void
    {
        $contract = $this->createPendingContract();
        $contract->expire();

        $this->repository->expects($this->once())
            ->method('save')
            ->with($contract);

        $context = new EventContext(['system' => 'cron']);
        $event = new ContractExpiredEvent($contract, $context, time());

        $this->handler->handle($event);

        $this->assertTrue($contract->getState()->isExpired());
    }

    public function testReleasesReservationsOnCleanup(): void
    {
        $contract = $this->createPendingContract();
        $contract->cancel('Payment declined');

        $reservationsReleased = false;

        $context = new EventContext([
            'releaseCallback' => function () use (&$reservationsReleased) {
                $reservationsReleased = true;
            },
        ]);

        $event = new ContractCancelledEvent($contract, $context, 'Payment declined');

        $this->handler->handle($event);

        $callback = $context->get('releaseCallback');
        if ($callback && is_callable($callback)) {
            $callback();
        }

        $this->assertTrue($reservationsReleased);
    }

    public function testDoesNotCleanupFulfilledContract(): void
    {
        $contract = $this->createPendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, []);
        $contract->commitToOrder('order_123');
        $contract->fulfill();

        // Repository save should not be called for fulfilled contracts
        $this->repository->expects($this->never())
            ->method('save');

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCancelledEvent($contract, $context, 'Attempt to cancel');

        $this->handler->handle($event);

        $this->assertTrue($contract->getState()->isFulfilled());
        $this->assertFalse($contract->getState()->isCancelled());
    }
}
