<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\SessionWriterInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Return\CheckoutReturnCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcher;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\EventListenerProvider;
use OxidEsales\PaymentBase\EventSystem\Handler\PaymentAuthorizedEventHandler;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\NullLogger;

/**
 * Sprint E: the `CheckoutReturnResponder` is the one place the
 * provider-neutral post-return steps live:
 *   1. build an EventContext carrying providerName + contract + any
 *      PSP-specific extras the caller passed in;
 *   2. call the resolver to translate the PSP outcome into a
 *      `ReturnResolution`;
 *   3. on success, dispatch `CheckoutReturnCompletedEvent` +
 *      `PaymentAuthorizedEvent` so the shared handler chain commits
 *      the contract;
 *   4. return the orderId the shared handlers wrote into the context
 *      (or null on failure), so each controller can translate to its
 *      own template / redirect convention.
 *
 * The responder has no knowledge of Stripe, PayPal, or OPC. All three
 * controllers call it the same way.
 */
#[CoversClass(CheckoutReturnResponder::class)]
final class CheckoutReturnResponderTest extends TestCase
{
    private EventDispatcherInterface&MockObject $dispatcher;
    private ReturnResolverInterface&MockObject $resolver;
    private PaymentContractInterface&MockObject $contract;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->resolver = $this->createMock(ReturnResolverInterface::class);
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contr_1');
        $this->contract->method('getOrderId')->willReturn('order_fallback');
        $this->contract->method('getAmount')->willReturn(42.0);
        $this->contract->method('getCurrency')->willReturn('EUR');
        $this->contract->method('getState')->willReturn(ContractState::pending());
    }

    /**
     * VR-20 R4 (S2) — the webhook commit (ContractCommitService) refused an amount
     * or currency that differs from the contract; the return leg dispatched the
     * PSP's figures unchecked. Both legs now pass one check.
     *
     * @return iterable<string, array{0: float, 1: string}>
     */
    public static function confirmationsThatDoNotMatchTheContract(): iterable
    {
        yield 'one cent short' => [41.99, 'EUR'];
        yield 'another currency' => [42.0, 'CHF'];
    }

    #[DataProvider('confirmationsThatDoNotMatchTheContract')]
    public function testAConfirmationThatDoesNotMatchTheContractCommitsNothing(float $amount, string $currency): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', $amount, $currency),
        );
        $this->dispatcher->expects(self::never())->method('dispatch');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(self::stringContains('does not match the contract'), self::arrayHasKey('contractAmount'));

        $responder = new CheckoutReturnResponder(
            $this->dispatcher,
            $this->createMock(SessionWriterInterface::class),
            $logger,
            $this->createMock(ContractRepositoryInterface::class),
            new HeadlessCheckoutScope(),
            new ThrowableSummary([]),
        );

        self::assertNull($responder->respond('stripe', $this->contract, $this->resolver, []));
    }

    public function testAnAlreadyProcessedReturnCarriesNoAmountAndIsNotChecked(): void
    {
        $this->resolver->method('resolve')->willReturn(ReturnResolution::alreadyProcessed('ord_1'));
        $this->dispatcher->expects(self::atLeastOnce())->method('dispatch')->willReturnArgument(0);

        self::assertSame('order_fallback', $this->buildResponder()->respond('stripe', $this->contract, $this->resolver, []));
    }

    public function testSuccessDispatchesCheckoutReturnCompletedAndPaymentAuthorized(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'),
        );

        $events = [];
        $this->dispatcher->method('dispatch')->willReturnCallback(
            static function (object $e) use (&$events): object {
                $events[] = $e;
                return $e;
            }
        );

        $responder = $this->buildResponder();
        $orderId = $responder->respond('stripe', $this->contract, $this->resolver, []);

        self::assertNotNull($orderId);
        self::assertCount(2, $events);
        self::assertInstanceOf(CheckoutReturnCompletedEvent::class, $events[0]);
        self::assertInstanceOf(PaymentAuthorizedEvent::class, $events[1]);
    }

    public function testContextCarriesProviderNameAndExtraKeys(): void
    {
        $this->resolver->method('resolve')->willReturnCallback(
            function ($contract, $context): ReturnResolution {
                self::assertSame('paypal', $context->get('providerName'));
                self::assertSame('sess_abc', $context->get('checkoutSessionId'));
                self::assertSame('contr_1', $context->get('contract_id'));
                self::assertSame($this->contract, $context->getContract());
                return ReturnResolution::readyToCommit('a', 'p', 42.0, 'EUR');
            }
        );

        $this->buildResponder()->respond(
            'paypal',
            $this->contract,
            $this->resolver,
            ['checkoutSessionId' => 'sess_abc'],
        );
    }

    public function testFailedResolutionReturnsNullAndSkipsDispatch(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::failed('declined', 'Card was declined'),
        );
        $this->dispatcher->expects(self::never())->method('dispatch');

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertNull($orderId);
    }

    public function testReadsOrderIdFromContextBeforeFallingBackToContract(): void
    {
        // ContractCommitmentHandler writes the orderId into the context
        // when it commits. The responder must prefer that.
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'),
        );
        $this->dispatcher->method('dispatch')->willReturnCallback(
            function (object $e) {
                if ($e instanceof CheckoutReturnCompletedEvent) {
                    $e->getContext()->set('orderId', 'order_from_context');
                }
                return $e;
            }
        );

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertSame('order_from_context', $orderId);
    }

    public function testFallsBackToContractOrderIdWhenContextHasNone(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('a', 'p', 42.0, 'EUR'),
        );

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertSame('order_fallback', $orderId);
    }

    public function testResolverExceptionBubblesAsNullNoLeakingStackTrace(): void
    {
        $this->resolver->method('resolve')->willThrowException(new RuntimeException('psp down'));
        $this->dispatcher->expects(self::never())->method('dispatch');

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertNull($orderId);
    }

    public function testRequiresCaptureFromResolutionPropagatesIntoContext(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::authorized('auth_1', 'ord_1', 42.0, 'EUR'),
        );

        $captured = null;
        $this->dispatcher->method('dispatch')->willReturnCallback(
            function (object $e) use (&$captured): object {
                if ($e instanceof CheckoutReturnCompletedEvent && $captured === null) {
                    $captured = $e->getContext()->get('requiresCapture');
                }
                return $e;
            }
        );

        $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertTrue($captured, 'requiresCapture should be set from the resolution');
    }

    // ── MOL-17: the return leg races the PSP webhook on the contract row ─────────────────────────

    public function testWhenTheWebhookAlreadyCommittedTheContractTheReturnLegYieldsAndReportsItsOrder(): void
    {
        $this->resolver->method('resolve')->willReturn(ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'));
        $this->dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            if ($event instanceof PaymentAuthorizedEvent) {
                throw new StaleContractException('contr_1', 2, 4);
            }
            return $event;
        });
        $fresh = $this->contractInState(ContractState::fulfilled(), 'order_from_webhook');
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findById')->with('contr_1')->willReturn($fresh);
        $written = [];

        $orderId = $this->buildResponder($contracts, $written)->respond('mollie', $this->contract, $this->resolver, []);

        self::assertSame('order_from_webhook', $orderId);
        self::assertSame(['order_from_webhook'], $written, 'the thank-you page still gets its order');
    }

    public function testWhenTheFreshContractIsStillOpenTheChainRunsOnceMoreOnIt(): void
    {
        $this->resolver->method('resolve')->willReturn(ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'));
        $fresh = $this->contractInState(ContractState::pending(), 'order_fresh');
        $seenContracts = [];
        $calls = 0;
        $this->dispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$calls, &$seenContracts): object {
            $calls++;
            if ($event instanceof PaymentAuthorizedEvent) {
                $seenContracts[] = $event->getContext()->getContract();
                if ($calls === 2) {
                    throw new StaleContractException('contr_1', 2, 3);
                }
            }
            return $event;
        });
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findById')->willReturn($fresh);

        $orderId = $this->buildResponder($contracts)->respond('mollie', $this->contract, $this->resolver, []);

        self::assertSame('order_fresh', $orderId);
        self::assertSame(4, $calls, 'two events, then the same two again on the fresh copy');
        self::assertSame($fresh, $seenContracts[1], 'the second pass works on the reloaded contract');
    }

    public function testASecondStaleRefusalIsLeftToTheWebhook(): void
    {
        $this->resolver->method('resolve')->willReturn(ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'));
        $this->dispatcher->method('dispatch')->willReturnCallback(static function (object $event): object {
            if ($event instanceof PaymentAuthorizedEvent) {
                throw new StaleContractException('contr_1', 2, 3);
            }
            return $event;
        });
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findById')->willReturn($this->contractInState(ContractState::pending(), 'ord_x'));

        self::assertNull($this->buildResponder($contracts)->respond('mollie', $this->contract, $this->resolver, []));
    }

    public function testWithoutARepositoryAStaleSaveIsReportedAsFailure(): void
    {
        $this->resolver->method('resolve')->willReturn(ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'));
        $this->dispatcher->method('dispatch')->willReturnCallback(static function (object $event): object {
            if ($event instanceof PaymentAuthorizedEvent) {
                throw new StaleContractException('contr_1', 2, 3);
            }
            return $event;
        });

        self::assertNull($this->buildResponder()->respond('mollie', $this->contract, $this->resolver, []));
    }

    /**
     * STRP-182: a draft contract has no order (the provider module did not create it before the redirect, or the
     * contract was reopened for a new attempt). The return refuses it before any event: the provider controller
     * shows its failure, and the error log lets the merchant find the confirmed money.
     */
    public function testRespond_WhenTheContractIsADraft_ReturnsNullAndDispatchesNothing(): void
    {
        $this->resolver->method('resolve')->willReturn(ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'));
        $this->dispatcher->expects(self::never())->method('dispatch');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('draft'),
            self::callback(static fn (array $context): bool => $context['contractId'] === 'contr_draft'
                && $context['authorizationId'] === 'auth_1'
                && $context['amount'] === 42.0),
        );
        $writer = $this->createMock(SessionWriterInterface::class);
        $writer->expects(self::never())->method('writeSessChallenge');

        $responder = new CheckoutReturnResponder(
            $this->dispatcher,
            $writer,
            $logger,
            $this->createMock(ContractRepositoryInterface::class),
            new HeadlessCheckoutScope(),
            new ThrowableSummary([]),
        );

        self::assertNull($responder->respond('stripe', $this->draftContract(), $this->resolver, []));
    }

    public function testRespond_WhenTheFreshContractIsADraft_ReturnsNullAfterOneDispatch(): void
    {
        $this->resolver->method('resolve')->willReturn(ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'));
        $calls = 0;
        $this->dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$calls): object {
            $calls++;
            if ($event instanceof PaymentAuthorizedEvent) {
                throw new StaleContractException('contr_1', 2, 3);
            }
            return $event;
        });
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findById')->willReturn($this->draftContract());

        self::assertNull($this->buildResponder($contracts)->respond('mollie', $this->contract, $this->resolver, []));
        self::assertSame(2, $calls, 'only the first pass dispatched; the draft copy gets no events');
    }

    private function draftContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contr_draft');
        $contract->method('getAmount')->willReturn(42.0);
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getState')->willReturn(ContractState::draft());
        $contract->method('getStateValue')->willReturn(ContractState::draft()->getValue());
        $contract->method('getOrderId')->willReturn(null);

        return $contract;
    }

    /**
     * STRP-182 / Mythos 2026.11-123, step 2: a provider module that did not create the order before the redirect
     * leaves the contract a draft. The return with a confirmed payment threw "Can only transition to PENDING from
     * NOT_FINISHED state" out of the responder. It must end as the handled failure (null), with the contract
     * still a draft and nothing committed.
     */
    public function testRespond_WhenTheContractIsADraftWithTheRealHandler_ReturnsNullAndLeavesTheContractADraft(): void
    {
        $contract = $this->realContract(withOrder: false);

        $orderId = $this->responderWithTheRealHandler()
            ->respond('stripe', $contract, $this->resolverConfirming($contract), []);

        self::assertNull($orderId);
        self::assertTrue($contract->getState()->isDraft());
    }

    /**
     * STRP-182 / Mythos 2026.11-123, step 1 (the control): with the order created before the customer leaves, the
     * same return moves the contract on.
     */
    public function testRespond_WhenTheContractIsPendingWithTheRealHandler_MovesItToReadyToCommit(): void
    {
        $contract = $this->realContract(withOrder: true);

        $orderId = $this->responderWithTheRealHandler()
            ->respond('stripe', $contract, $this->resolverConfirming($contract), []);

        self::assertSame('order_early', $orderId);
        self::assertTrue($contract->getState()->isReadyToCommit());
    }

    private function realContract(bool $withOrder): PaymentContract
    {
        $contract = new PaymentContract(1, 'user_1', BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 42.0,
            'totalNet' => 35.29,
            'totalVat' => 6.71,
            'currency' => 'EUR',
        ]), 'contr_real');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        if ($withOrder) {
            $contract->transitionToNotFinished('order_early');
            $contract->transitionToPending();
        }

        return $contract;
    }

    private function resolverConfirming(PaymentContract $contract): ReturnResolverInterface
    {
        $resolver = $this->createMock(ReturnResolverInterface::class);
        $resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', $contract->getAmount(), $contract->getCurrency()),
        );

        return $resolver;
    }

    private function responderWithTheRealHandler(): CheckoutReturnResponder
    {
        $handler = new PaymentAuthorizedEventHandler(
            $this->createMock(ContractRepositoryInterface::class),
            new EventDispatcher(new EventListenerProvider([])),
            $this->createMock(OrderPaymentStateServiceInterface::class),
        );

        return new CheckoutReturnResponder(
            new EventDispatcher(new EventListenerProvider([$handler])),
            $this->createMock(SessionWriterInterface::class),
            new NullLogger(),
            $this->createMock(ContractRepositoryInterface::class),
            new HeadlessCheckoutScope(),
            new ThrowableSummary([]),
        );
    }

    private function contractInState(ContractState $state, string $orderId): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contr_1');
        $contract->method('getState')->willReturn($state);
        $contract->method('getStateValue')->willReturn($state->getValue());
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }

    /**
     * @param list<string> $written collects the order ids handed to the session writer
     */
    /**
     * Sprint 15 / S3: `sess_challenge` is what makes the Twig thank-you page
     * find its order. A headless return has no session and no thank-you page;
     * writing it would only touch a session nobody reads.
     */
    public function testAHeadlessReturnWritesNoSessionChallenge(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'),
        );
        $scope = new HeadlessCheckoutScope();
        $scope->enter('contr_1', userId: 'user-1');
        $written = [];

        $orderId = $this->buildResponder(written: $written, scope: $scope)
            ->respond('stripe', $this->contract, $this->resolver, []);

        self::assertSame('order_fallback', $orderId, 'the order is still reported');
        self::assertSame([], $written);
    }

    public function testASessionReturnStillWritesTheSessionChallenge(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'),
        );
        $written = [];

        $this->buildResponder(written: $written, scope: new HeadlessCheckoutScope())
            ->respond('stripe', $this->contract, $this->resolver, []);

        self::assertSame(['order_fallback'], $written);
    }

    private function buildResponder(
        ?ContractRepositoryInterface $contracts = null,
        array &$written = [],
        ?HeadlessCheckoutScope $scope = null,
    ): CheckoutReturnResponder {
        // The real writer uses OXID's Registry::getSession(); tests
        // only care about dispatch + context + orderId return, so pass
        // a no-op writer.
        $writer = new class ($written) implements SessionWriterInterface {
            /** @param list<string> $written */
            public function __construct(private array &$written)
            {
            }

            public function writeSessChallenge(string $orderId): void
            {
                $this->written[] = $orderId;
            }
        };
        return new CheckoutReturnResponder(
            $this->dispatcher,
            $writer,
            new NullLogger(),
            $contracts ?? $this->createMock(ContractRepositoryInterface::class),
            $scope ?? new HeadlessCheckoutScope(),
            new ThrowableSummary([])
        );
    }
}
