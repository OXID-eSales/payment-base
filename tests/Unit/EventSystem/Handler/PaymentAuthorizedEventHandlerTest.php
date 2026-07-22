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
            new EventDispatcher(),
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
}
