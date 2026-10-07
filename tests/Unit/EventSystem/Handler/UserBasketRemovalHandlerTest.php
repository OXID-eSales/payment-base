<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\UserBasketRemovalHandler;
use PHPUnit\Framework\TestCase;

final class RecordingBasketRemoval extends UserBasketRemovalHandler
{
    /** @var list<string> */
    public array $deleted = [];

    public bool $deleteSucceeds = true;

    protected function deleteUserBasket(string $basketId): bool
    {
        $this->deleted[] = $basketId;

        return $this->deleteSucceeds;
    }
}

/**
 * Sprint 15 / S6 — core's placeOrder deletes the user basket after the order
 * so it cannot be ordered twice (BeforeBasketRemoveOnPlaceOrder). Our commit
 * is the moment the order is real, so the basket a headless contract was paid
 * from goes then - and never on cancel, so the shopper can retry.
 */
final class UserBasketRemovalHandlerTest extends TestCase
{
    public function testHandlesContractCommitted(): void
    {
        self::assertInstanceOf(HandlerInterface::class, new RecordingBasketRemoval());
        self::assertSame(ContractCommittedEvent::class, UserBasketRemovalHandler::getHandledEventClass());
    }

    public function testDeletesTheBasketTheContractWasPaidFrom(): void
    {
        $handler = new RecordingBasketRemoval();
        $contract = $this->committedContract(basketId: 'ub-1');

        $handler->handle(new ContractCommittedEvent($contract, new EventContext(), 'order-1'));

        self::assertSame(['ub-1'], $handler->deleted);
    }

    public function testASessionContractHasNoBasketToDelete(): void
    {
        $handler = new RecordingBasketRemoval();

        $handler->handle(new ContractCommittedEvent($this->committedContract(basketId: null), new EventContext(), 'order-1'));

        self::assertSame([], $handler->deleted);
    }

    public function testIgnoresOtherEvents(): void
    {
        $handler = new RecordingBasketRemoval();

        $handler->handle(new ContractDraftCompletedEvent($this->committedContract('ub-1'), new EventContext()));

        self::assertSame([], $handler->deleted);
    }

    public function testAFailedDeleteDoesNotBreakTheCommit(): void
    {
        $handler = new RecordingBasketRemoval();
        $handler->deleteSucceeds = false;

        $handler->handle(new ContractCommittedEvent($this->committedContract('ub-1'), new EventContext(), 'order-1'));

        self::assertSame(['ub-1'], $handler->deleted);
    }

    private function committedContract(?string $basketId): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, 'contract-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        if ($basketId !== null) {
            $contract->setMetadata('basket_id', $basketId);
        }
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');

        return $contract;
    }
}
