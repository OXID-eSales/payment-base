<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningService;
use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<EventInterface> */
    public array $events = [];

    public function dispatch(EventInterface $event): EventInterface
    {
        $this->events[] = $event;

        return $event;
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
    }

    public function removeListener(string $eventClass, callable $listener): void
    {
    }
}

/**
 * Sprint 15 / S7 — "open a contract for a user basket, no PSP session": the
 * first half of a provider's Place Order, provider-neutral, for channels that
 * pay with a delegated token later (ACP `create_checkout`). Same event chain
 * as every other checkout: ContractDraftCompletedEvent → early order →
 * PENDING.
 */
final class ContractOpeningServiceTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;

    private ContractRepositoryInterface&MockObject $contracts;

    private RecordingDispatcher $dispatcher;

    private HeadlessCheckoutScope $scope;

    private RecordingBasketProvider $baskets;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->dispatcher = new RecordingDispatcher();
        $this->scope = new HeadlessCheckoutScope();
        $this->baskets = new RecordingBasketProvider();
    }

    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(ContractOpeningServiceInterface::class, $this->service());
    }

    public function testOpensAContractForTheUserBasketAndRunsTheDraftCompletedChain(): void
    {
        $contract = $this->draft('contract-1');
        $this->contractService->expects($this->once())->method('createContract')
            ->with('user-1', $this->isInstanceOf(HeadlessShopBasket::class))
            ->willReturn($contract);
        $this->contracts->method('findById')->with('contract-1')->willReturn($contract);
        $this->contracts->expects($this->once())->method('save')->with($contract);

        $opened = $this->service()->open('user-1', 'ub-1', 'oe_payments_stripe_wallet', 'acp');

        self::assertSame($contract, $opened);
        self::assertSame('ub-1', $this->baskets->askedWith?->basketId);
        self::assertSame('oe_payments_stripe_wallet', $this->baskets->askedWith?->paymentId);
        self::assertSame('ub-1', $contract->getMetadata('basket_id'));
        self::assertSame('acp', $contract->getMetadata('channel'));

        self::assertCount(1, $this->dispatcher->events);
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(ContractDraftCompletedEvent::class, $event);
        self::assertSame($contract, $event->getContract());
        $context = $event->getContext();
        self::assertSame('oe_payments_stripe_wallet', $context->get('paymentId'));
        self::assertSame('ub-1', $context->get('basketId'));
        self::assertSame('headless:ub-1', $context->get('sessionId'));
        self::assertSame('acp', $context->get('channel'));
        self::assertTrue($context->get('headless'));

        self::assertSame('contract-1', $this->scope->getScopeId(), 'the scope ends on the contract');
        self::assertSame('ub-1', $this->scope->getBasketId());
    }

    public function testTheBasketScopeIsEnteredBeforeTheChainRuns(): void
    {
        $contract = $this->draft('contract-1');
        $this->contractService->method('createContract')->willReturn($contract);
        $this->contracts->method('findById')->willReturn($contract);
        $dispatcher = new class ($this->scope) extends RecordingDispatcher {
            public ?string $scopeWhenDispatched = null;

            public function __construct(private readonly HeadlessCheckoutScope $scope)
            {
            }

            public function dispatch(EventInterface $event): EventInterface
            {
                $this->scopeWhenDispatched = $this->scope->getScopeId();

                return parent::dispatch($event);
            }
        };
        $this->dispatcher = $dispatcher;

        $this->service()->open('user-1', 'ub-1', 'oe_payments_stripe_wallet', 'acp');

        self::assertSame('ub-1', $dispatcher->scopeWhenDispatched);
    }

    public function testAnswersTheReloadedContractWhenTheChainMovedIt(): void
    {
        $draft = $this->draft('contract-1');
        $moved = $this->draft('contract-1');
        $moved->transitionToNotFinished('order-1');
        $moved->transitionToPending();
        $this->contractService->method('createContract')->willReturn($draft);
        $this->contracts->method('findById')->willReturn($moved);

        $opened = $this->service()->open('user-1', 'ub-1', 'oe_payments_stripe_wallet', 'acp');

        self::assertSame($moved, $opened);
        self::assertTrue($opened->getState()->isPending());
    }

    public function testAnUnknownBasketIsRefusedBeforeAnyContractExists(): void
    {
        $this->baskets = new RecordingBasketProvider(basket: null);
        $this->contractService->expects($this->never())->method('createContract');

        try {
            $this->service()->open('user-1', 'missing', 'oe_payments_stripe_wallet', 'acp');
            self::fail('no basket, no contract');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::BASKET_NOT_FOUND, $e->errorCode);
        }
        self::assertSame([], $this->dispatcher->events);
    }

    private function service(): ContractOpeningService
    {
        return new ContractOpeningService(
            $this->contractService,
            $this->contracts,
            $this->dispatcher,
            $this->baskets,
            $this->scope,
        );
    }

    private function draft(string $id): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        return $contract;
    }
}
