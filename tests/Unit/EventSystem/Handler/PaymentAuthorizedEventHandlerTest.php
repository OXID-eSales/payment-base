<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcher;
use OxidEsales\PaymentBase\EventSystem\Handler\PaymentAuthorizedEventHandler;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\EventSystem\EventListenerProvider;

/**
 * Guards the agnostic contract of PaymentAuthorizedEventHandler: the provider
 * identity is taken from the context and NEVER guessed. Previously the handler
 * fell back to a hardcoded 'stripe' when providerName was absent — a leak that
 * misattributed non-Stripe contracts.
 */
class PaymentAuthorizedEventHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $repository;
    private OrderPaymentStateServiceInterface&MockObject $orderPaymentState;
    private PaymentAuthorizedEventHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->orderPaymentState = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->handler = new PaymentAuthorizedEventHandler(
            $this->repository,
            new EventDispatcher(new EventListenerProvider([])),
            $this->orderPaymentState
        );
    }

    private function createPendingContract(): PaymentContract
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

        return $contract;
    }

    public function testSetsProviderFromContextForAnyProvider(): void
    {
        $contract = $this->createPendingContract();
        $this->repository->expects($this->once())->method('save');

        $context = new EventContext(['providerName' => 'paypal']);
        $context->setContract($contract);
        $event = new PaymentAuthorizedEvent($context, 'auth_1', 'ppo_9', 100.0, 'EUR');

        $this->handler->handle($event);

        // Proves the provider is read from context, not hardcoded to 'stripe'.
        $this->assertSame('paypal', $contract->getProvider());
        $this->assertSame('ppo_9', $contract->getProviderOrderId());
    }

    public function testDoesNotGuessStripeWhenProviderNameAbsent(): void
    {
        $contract = $this->createPendingContract();
        // A provider-specific handler set the provider earlier in the flow.
        $contract->setProvider('paypal', 'ppo_9');

        $this->repository->expects($this->once())->method('save');

        // No 'providerName' in context.
        $context = new EventContext([]);
        $context->setContract($contract);
        $event = new PaymentAuthorizedEvent($context, 'auth_1', 'ppo_9', 100.0, 'EUR');

        $this->handler->handle($event);

        // Regression lock: the old code overwrote this to 'stripe'.
        $this->assertSame('paypal', $contract->getProvider());
    }

    /**
     * Sprint 147 (AW): the transaction id links the order to the provider payment (STRP-74).
     */
    public function testHandle_WhenAuthorizedForAnEarlyOrder_WritesTheTransactionIdToTheOrder(): void
    {
        $contract = $this->createPendingContract();
        $this->orderPaymentState->expects($this->once())->method('updateTransactionId')->with('order_123', 'pi_9');

        $context = new EventContext(['providerName' => 'stripe']);
        $context->setContract($contract);

        $this->handler->handle(new PaymentAuthorizedEvent($context, 'auth_1', 'pi_9', 100.0, 'EUR'));
    }

    public function testHandle_WhenAllConditionsAreFulfilled_MovesTheContractToReadyToCommit(): void
    {
        $contract = $this->createPendingContract();
        $context = new EventContext(['providerName' => 'stripe']);
        $context->setContract($contract);

        $this->handler->handle(new PaymentAuthorizedEvent($context, 'auth_1', 'pi_9', 100.0, 'EUR'));

        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

    /**
     * Sprint 147 (AW) Story 2: a refused write of the transaction id leaves the handler before the
     * contract is saved. Before, the `false` of the service was ignored.
     */
    public function testHandle_WhenTheTransactionIdWriteFails_DoesNotSaveTheContract(): void
    {
        $contract = $this->createPendingContract();
        $this->orderPaymentState->method('updateTransactionId')
            ->willThrowException(new \RuntimeException('Failed to update OXTRANSID'));
        $this->repository->expects($this->never())->method('save');

        $context = new EventContext(['providerName' => 'stripe']);
        $context->setContract($contract);

        $this->expectException(\RuntimeException::class);

        $this->handler->handle(new PaymentAuthorizedEvent($context, 'auth_1', 'pi_9', 100.0, 'EUR'));
    }

    /**
     * STRP-182 / Mythos 2026.11-123: a draft contract has no order, so a payment confirmation cannot be booked on
     * it. Before, a dead branch called transitionToPending(), which the contract allows only from NOT_FINISHED;
     * the "Can only transition to PENDING" error said nothing about the real cause. The refusal names the contract
     * and its state, and nothing is saved.
     */
    public function testHandle_WhenTheContractIsADraft_ThrowsWithoutSavingTheContract(): void
    {
        $contract = new PaymentContract(1, 'user123', BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]), 'contract_draft_1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $this->repository->expects($this->never())->method('save');

        $context = new EventContext(['providerName' => 'stripe']);
        $context->setContract($contract);

        try {
            $this->handler->handle(new PaymentAuthorizedEvent($context, 'auth_1', 'pi_9', 100.0, 'EUR'));
            $this->fail('A draft contract must be refused');
        } catch (\DomainException $refusal) {
            $this->assertStringContainsString('contract_draft_1 is a draft and has no order', $refusal->getMessage());
        }

        $this->assertTrue($contract->getState()->isDraft());
        $this->assertNull($context->get('authorizationId'), 'a refused confirmation writes nothing into the context');
    }
}
