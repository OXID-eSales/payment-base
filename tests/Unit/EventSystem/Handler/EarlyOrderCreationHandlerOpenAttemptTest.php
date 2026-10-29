<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\EarlyOrderWriter;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Adapter\ShopOrderRewriterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Checkout\Context\SessionCheckoutContext;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistry;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\EarlyOrderCreationHandler;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\Checkout\RecordingSessionAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 — one order, one contract. A new contract gets a new early order; a
 * contract that continues the attempt the customer has open (it already has
 * its order) gets that order written again. Nothing is retired: the previous
 * attempt IS this contract. The attempt is remembered for the next start.
 */
final class EarlyOrderCreationHandlerOpenAttemptTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $orderService;
    private ShopOrderRewriterInterface&MockObject $orderRewriter;
    private OpenCheckoutAttemptRegistry $openAttempts;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->orderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->orderRewriter = $this->createMock(ShopOrderRewriterInterface::class);
        $this->openAttempts = new OpenCheckoutAttemptRegistry(new SessionCheckoutContext(new RecordingSessionAdapter()));
    }

    public function testANewContract_GetsANewOrderAndIsRemembered(): void
    {
        $this->orderService->expects($this->once())->method('createOrder')->willReturn($this->orderResponse('order-new'));
        $this->orderRewriter->expects($this->never())->method('rewriteOrder');

        $contract = $this->handle($this->draftContract('contract-1'));

        $this->assertSame('order-new', $contract->getOrderId());
        $this->assertTrue($contract->getState()->isPending());
        $this->assertSame('contract-1', $this->openAttempts->peek());
    }

    public function testAContractThatHasItsOrder_GetsThatOrderWrittenAgain(): void
    {
        $contract = $this->draftContract('contract-1');
        $contract->transitionToNotFinished('order-kept');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'cs_1');
        $contract->reopenForNewAttempt(AttemptClosure::Closed, $this->basket(), [ContractCondition::TYPE_PAYMENT_AUTHORIZED]);
        $this->orderService->expects($this->never())->method('createOrder');
        $this->orderRewriter->expects($this->once())->method('rewriteOrder')
            ->with('order-kept', $this->callback(static fn (CreateOrderRequest $r): bool => $r->metadata['contract_id'] === 'contract-1'))
            ->willReturn($this->orderResponse('order-kept'));

        $this->handle($contract);

        $this->assertSame('order-kept', $contract->getOrderId());
        $this->assertTrue($contract->getState()->isPending());
        $this->assertSame('contract-1', $this->openAttempts->peek());
    }

    public function testAHeadlessAttemptStampsItsBasketIdOnTheContract(): void
    {
        $this->orderService->method('createOrder')->willReturn($this->orderResponse('order-new'));

        $contract = $this->handle($this->draftContract('contract-h1'), basketId: 'ub-1');

        $this->assertSame('ub-1', $contract->getMetadata('basket_id'));
    }

    public function testASessionAttemptStampsNoBasketId(): void
    {
        $this->orderService->method('createOrder')->willReturn($this->orderResponse('order-new'));

        $contract = $this->handle($this->draftContract('contract-s1'));

        $this->assertNull($contract->getMetadata('basket_id'));
    }

    private function handle(PaymentContract $contract, ?string $basketId = null): PaymentContract
    {
        $context = ['paymentId' => 'oe_payments_mollie'];
        if ($basketId !== null) {
            $context['basketId'] = $basketId;
        }

        $handler = new EarlyOrderCreationHandler(
            $this->contractRepository,
            new EarlyOrderWriter($this->orderService, $this->orderRewriter),
            $this->openAttempts,
            $this->createMock(EventDispatcherInterface::class),
        );
        $handler->handle(new ContractDraftCompletedEvent($contract, new EventContext($context)));

        return $contract;
    }

    private function draftContract(string $id): PaymentContract
    {
        $contract = new PaymentContract(1, 'user123', $this->basket(), $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        return $contract;
    }

    private function basket(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]);
    }

    private function orderResponse(string $orderId): OrderResponse
    {
        return new OrderResponse(
            orderId: $orderId,
            orderNumber: 1001,
            userId: 'user123',
            totalAmount: 100.0,
            currency: 'EUR',
            status: 'not_finished',
            paymentId: 'oe_payments_mollie',
            paymentTransactionId: null,
            createdAt: new DateTimeImmutable(),
            metadata: [],
            shopData: []
        );
    }
}
