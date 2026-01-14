<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\ContractCleanupHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractExpiredEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

class ContractCleanupHandlerTest extends TestCase
{
    private ContractRepository $repository;
    private ContractCleanupHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new ContractRepository();
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
        $this->repository->save($contract);

        $context = new EventContext(['userId' => 'user123']);
        $event = new ContractCancelledEvent($contract, $context, 'User requested cancellation');

        $this->handler->handle($event);

        $updated = $this->repository->findById($contract->getId());

        $this->assertTrue($updated->getState()->isCancelled());
    }

    public function testExpiresContractOnExpiredEvent(): void
    {
        $contract = $this->createPendingContract();
        $contract->expire();
        $this->repository->save($contract);

        $context = new EventContext(['system' => 'cron']);
        $event = new ContractExpiredEvent($contract, $context, time());

        $this->handler->handle($event);

        $updated = $this->repository->findById($contract->getId());

        $this->assertTrue($updated->getState()->isExpired());
    }

    public function testReleasesReservationsOnCleanup(): void
    {
        $contract = $this->createPendingContract();
        $contract->cancel('Payment declined');
        $this->repository->save($contract);

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
        $this->repository->save($contract);

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCancelledEvent($contract, $context, 'Attempt to cancel');

        $this->handler->handle($event);

        $updated = $this->repository->findById($contract->getId());

        $this->assertTrue($updated->getState()->isFulfilled());
        $this->assertFalse($updated->getState()->isCancelled());
    }
}
