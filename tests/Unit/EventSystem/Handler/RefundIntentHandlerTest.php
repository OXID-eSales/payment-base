<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\EventSystem\Broker\EventBrokerInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundIntentEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\PaymentCaptureStatusQueryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 03 (2026-05-19) — verifies that the routing decision
 * (refund / cancel-auth / partial-capture) lives in payment-base
 * and is driven by `PaymentContractInterface` state, with PSP
 * capture-status disambiguation for committed-state contracts.
 *
 * Lifted-and-shifted from opalreturns' `PaymentBaseRefundBrokerListener`
 * (Sprint 04 deletes it).
 */
class RefundIntentHandlerTest extends TestCase
{
    public function testNoOpWhenContractRepoIsNull(): void
    {
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->never())->method('dispatch');

        $handler = new RefundIntentHandler(null, $broker);
        $handler->__invoke($this->stubIntent('order-1', 10.0));
    }

    public function testNoOpWhenBrokerIsNull(): void
    {
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->expects($this->never())->method('findByOrderId');

        $handler = new RefundIntentHandler($contracts, null);
        $handler->__invoke($this->stubIntent('order-1', 10.0));
    }

    public function testNoOpWhenOrderIdIsEmpty(): void
    {
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->never())->method('dispatch');

        $handler = new RefundIntentHandler(
            $this->createMock(ContractRepositoryInterface::class),
            $broker,
        );
        $handler->__invoke($this->stubIntent('', 10.0));
    }

    public function testNoOpWhenContractNotFoundForOrder(): void
    {
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findByOrderId')->willReturn(null);
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->never())->method('dispatch');

        $handler = new RefundIntentHandler($contracts, $broker);
        $handler->__invoke($this->stubIntent('order-1', 10.0));
    }

    public function testAuthorizedStateFullRefundDispatchesCancelAuthorization(): void
    {
        $contract = $this->authorizedContract(authorizedAmount: 50.0);
        $broker = $this->expectDispatchInstanceOf(CancelAuthorizationRequestedEvent::class);

        $handler = new RefundIntentHandler($this->contractsReturning('order-1', $contract), $broker);
        $handler->__invoke($this->stubIntent('order-1', 50.0));
    }

    public function testAuthorizedStatePartialRefundDispatchesCaptureForKeptAmount(): void
    {
        $contract = $this->authorizedContract(authorizedAmount: 100.0);
        $captured = null;
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (AbstractProviderRequestEvent $event) use (&$captured) {
                $captured = $event;
                return $event;
            });

        $handler = new RefundIntentHandler($this->contractsReturning('order-1', $contract), $broker);
        $handler->__invoke($this->stubIntent('order-1', 30.0));

        $this->assertInstanceOf(CaptureRequestedEvent::class, $captured);
        // kept amount = authorized − refund = 100 − 30 = 70
        $this->assertSame(70.0, $captured->getAmount());
    }

    public function testAuthorizedStateSubEpsilonDifferenceCountsAsFullCancel(): void
    {
        $contract = $this->authorizedContract(authorizedAmount: 100.0);
        $broker = $this->expectDispatchInstanceOf(CancelAuthorizationRequestedEvent::class);

        $handler = new RefundIntentHandler($this->contractsReturning('order-1', $contract), $broker);
        $handler->__invoke($this->stubIntent('order-1', 99.999));
    }

    public function testAuthorizedStateRefundAboveAuthorizedLogsAndSkips(): void
    {
        $contract = $this->authorizedContract(authorizedAmount: 50.0);
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->never())->method('dispatch');

        $handler = new RefundIntentHandler($this->contractsReturning('order-1', $contract), $broker);
        $handler->__invoke($this->stubIntent('order-1', 100.0));
    }

    public function testAuthorizedStateRefundLessThanOrEqualZeroLogsAndSkips(): void
    {
        $contract = $this->authorizedContract(authorizedAmount: 50.0);
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->never())->method('dispatch');

        $handler = new RefundIntentHandler($this->contractsReturning('order-1', $contract), $broker);
        $handler->__invoke($this->stubIntent('order-1', 0.0));
    }

    public function testCommittedStateWithCaptureStatusTrueDispatchesRefund(): void
    {
        $contract = $this->fulfilledContract();
        $broker = $this->expectDispatchInstanceOf(RefundRequestedEvent::class);

        $handler = new RefundIntentHandler(
            $this->contractsReturning('order-1', $contract),
            $broker,
            $this->captureStatus(true),
        );
        $handler->__invoke($this->stubIntent('order-1', 25.0));
    }

    public function testCommittedStateWithCaptureStatusFalseRoutesAuthorizedBranch(): void
    {
        // PSP says "no money moved" → handler should treat as
        // AUTHORIZED — full refund maps to CancelAuthorization.
        $contract = $this->fulfilledContract();
        $broker = $this->expectDispatchInstanceOf(CancelAuthorizationRequestedEvent::class);

        $handler = new RefundIntentHandler(
            $this->contractsReturning('order-1', $contract),
            $broker,
            $this->captureStatus(false),
        );
        // refund equal to contract authorized amount → cancel branch
        $handler->__invoke($this->stubIntent('order-1', $contract->getAmount()));
    }

    public function testCommittedStateWithCaptureStatusNullFallsBackToRefund(): void
    {
        // Capture status unknown (offline / unsupported provider) →
        // conservative fallback: dispatch refund. If PSP really hadn't
        // captured, it will reject and the admin sees the visible error.
        $contract = $this->fulfilledContract();
        $broker = $this->expectDispatchInstanceOf(RefundRequestedEvent::class);

        $handler = new RefundIntentHandler(
            $this->contractsReturning('order-1', $contract),
            $broker,
            $this->captureStatus(null),
        );
        $handler->__invoke($this->stubIntent('order-1', 25.0));
    }

    public function testPendingStateLogsAndSkips(): void
    {
        $contract = $this->pendingContract();
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->never())->method('dispatch');

        $handler = new RefundIntentHandler($this->contractsReturning('order-1', $contract), $broker);
        $handler->__invoke($this->stubIntent('order-1', 10.0));
    }

    public function testDispatchedEventCarriesCorrelationContextFromIntent(): void
    {
        $contract = $this->fulfilledContract();
        $captured = null;
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (AbstractProviderRequestEvent $event) use (&$captured) {
                $captured = $event;
                return $event;
            });

        $handler = new RefundIntentHandler(
            $this->contractsReturning('order-1', $contract),
            $broker,
            $this->captureStatus(true),
        );
        $handler->__invoke($this->stubIntent(
            'order-1',
            25.0,
            correlation: ['returnId' => 'ret-42', 'initiator' => 'opalreturns'],
        ));

        $this->assertInstanceOf(AbstractProviderRequestEvent::class, $captured);
        $this->assertSame('ret-42', $captured->getContext()->get('returnId'));
        $this->assertSame('opalreturns', $captured->getContext()->get('initiator'));
    }

    public function testDispatchedEventCarriesProviderNameFromContract(): void
    {
        $contract = $this->fulfilledContract(provider: 'stripe');
        $captured = null;
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (AbstractProviderRequestEvent $event) use (&$captured) {
                $captured = $event;
                return $event;
            });

        $handler = new RefundIntentHandler(
            $this->contractsReturning('order-1', $contract),
            $broker,
            $this->captureStatus(true),
        );
        $handler->__invoke($this->stubIntent('order-1', 25.0));

        $this->assertSame('stripe', $captured->getContext()->get('providerName'));
    }

    public function testDispatchedEventContextCarriesContractIdAndOrderId(): void
    {
        $contract = $this->fulfilledContract();
        $captured = null;
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (AbstractProviderRequestEvent $event) use (&$captured) {
                $captured = $event;
                return $event;
            });

        $handler = new RefundIntentHandler(
            $this->contractsReturning('order-1', $contract),
            $broker,
            $this->captureStatus(true),
        );
        $handler->__invoke($this->stubIntent('order-1', 25.0));

        $ctx = $captured->getContext();
        $this->assertSame('order-1', $ctx->get('orderId'));
        $this->assertSame($contract->getId(), $ctx->get('contractId'));
    }

    public function testHandlerIgnoresNonIntentEventsGracefully(): void
    {
        // LSP guard: PSR-14 listeners can be registered against an
        // interface; the dispatcher will call __invoke with anything
        // assignable. Passing a non-intent must no-op (no broker call,
        // no exception).
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->never())->method('dispatch');

        $handler = new RefundIntentHandler(
            $this->createMock(ContractRepositoryInterface::class),
            $broker,
        );

        $handler->__invoke(new \stdClass());
    }

    // ------------------ helpers ------------------

    private function stubIntent(
        string $orderId,
        ?float $amount,
        ?string $reason = 'return_credit',
        array $correlation = [],
    ): RefundIntentEventInterface {
        $intent = $this->createMock(RefundIntentEventInterface::class);
        $intent->method('getOrderId')->willReturn($orderId);
        $intent->method('getAmount')->willReturn($amount);
        $intent->method('getReason')->willReturn($reason);
        $intent->method('getCorrelationContext')->willReturn($correlation);
        return $intent;
    }

    private function contractsReturning(string $orderId, PaymentContractInterface $contract): ContractRepositoryInterface
    {
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findByOrderId')->with($orderId)->willReturn($contract);
        return $contracts;
    }

    private function expectDispatchInstanceOf(string $eventClass): EventBrokerInterface
    {
        $broker = $this->createMock(EventBrokerInterface::class);
        $broker->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (AbstractProviderRequestEvent $event) use ($eventClass) {
                self::assertInstanceOf($eventClass, $event);
                return $event;
            });
        return $broker;
    }

    private function captureStatus(?bool $captured): PaymentCaptureStatusQueryInterface
    {
        $q = $this->createMock(PaymentCaptureStatusQueryInterface::class);
        $q->method('isPaymentCaptured')->willReturn($captured);
        return $q;
    }

    private function authorizedContract(float $authorizedAmount): PaymentContract
    {
        $contract = $this->makeContract(totalGross: $authorizedAmount);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->authorize();
        return $contract;
    }

    private function pendingContract(): PaymentContract
    {
        $contract = $this->makeContract();
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        return $contract;
    }

    private function fulfilledContract(?string $provider = null): PaymentContract
    {
        $contract = $this->makeContract();
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $contract->fulfill();
        if ($provider !== null) {
            $contract->setProvider($provider, 'pi_123');
        }
        return $contract;
    }

    private function makeContract(float $totalGross = 100.0): PaymentContract
    {
        $basket = BasketSnapshot::fromArray([
            'items'      => [],
            'discounts'  => [],
            'totalGross' => $totalGross,
            'totalNet'   => $totalGross,
            'totalVat'   => 0.0,
            'currency'   => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
        return new PaymentContract(1, 'user-1', $basket);
    }
}