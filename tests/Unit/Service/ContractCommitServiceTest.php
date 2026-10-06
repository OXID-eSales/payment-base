<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitService;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A dispatcher that records events and plays the handler chain's part: on
 * PaymentAuthorizedEvent it fulfils the contract the way
 * PaymentAuthorizedEventHandler + ContractCommitmentHandler would, or leaves
 * it pending, or reports the contract stale - whatever the test scripted.
 */
final class ScriptedChainDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    /** @var list<string> */
    public array $script;

    /**
     * @param list<string> $script one entry per PaymentAuthorizedEvent: 'commit' | 'pending' | 'stale'
     */
    public function __construct(array $script = ['commit'])
    {
        $this->script = $script;
    }

    public function dispatch(EventInterface $event): EventInterface
    {
        $this->events[] = $event;
        if (!$event instanceof PaymentAuthorizedEvent) {
            return $event;
        }

        $step = array_shift($this->script) ?? 'commit';
        if ($step === 'stale') {
            throw new StaleContractException('contract-1', 3, 2);
        }

        $contract = $event->getContext()->getContract();
        if ($step === 'commit' && $contract instanceof PaymentContract) {
            $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, []);
            $contract->commitToOrder((string) $contract->getOrderId());
            $event->getContext()->set('orderId', $contract->getOrderId());
        }

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
 * Sprint 15 / S4 — "the provider says paid": commit the contract if it is
 * still open and the amount matches, idempotently. Until now only the
 * browser return leg (CheckoutReturnResponder) raised PaymentAuthorizedEvent;
 * a headless client that never comes back (closed tab, killed app) left a
 * paid PSP session on a PENDING contract that the stale cleanup later
 * cancelled - money taken, no order. Webhooks call this instead of skipping
 * on "not committed yet".
 */
final class ContractCommitServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(
            ContractCommitServiceInterface::class,
            new ContractCommitService($this->contracts, new ScriptedChainDispatcher())
        );
    }

    public function testAnUnknownContractIsRefused(): void
    {
        $this->contracts->method('findById')->willReturn(null);
        $dispatcher = new ScriptedChainDispatcher();

        $outcome = $this->service($dispatcher)->commit($this->confirmation());

        self::assertSame(CommitOutcome::REFUSED, $outcome->outcome);
        self::assertSame('contract_not_found', $outcome->reason);
        self::assertSame([], $dispatcher->events);
    }

    /**
     * The return leg and the webhook race; whichever is second finds the
     * contract committed. That is success, not an error, and must not raise
     * the chain again.
     */
    public function testACommittedOrFulfilledContractIsAlreadyProcessed(): void
    {
        $committed = $this->pendingContract();
        $committed->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $committed->commitToOrder('order-1');
        $this->contracts->method('findById')->willReturn($committed);
        $dispatcher = new ScriptedChainDispatcher();

        $outcome = $this->service($dispatcher)->commit($this->confirmation());

        self::assertSame(CommitOutcome::ALREADY_PROCESSED, $outcome->outcome);
        self::assertSame('order-1', $outcome->orderId);
        self::assertTrue($outcome->isSettled());
        self::assertSame([], $dispatcher->events);
    }

    /**
     * The hazard this sprint closes, seen from the other side: the PSP says
     * paid but the contract was cancelled meanwhile. Nothing can be committed;
     * it is refused loudly so the merchant finds the money.
     */
    public function testATerminalContractIsRefusedNotCommitted(): void
    {
        $cancelled = $this->pendingContract();
        $cancelled->cancel('stale cleanup');
        $this->contracts->method('findById')->willReturn($cancelled);
        $dispatcher = new ScriptedChainDispatcher();

        $outcome = $this->service($dispatcher)->commit($this->confirmation());

        self::assertSame(CommitOutcome::REFUSED, $outcome->outcome);
        self::assertSame('contract_cancelled', $outcome->reason);
        self::assertSame([], $dispatcher->events);
    }

    public function testAnAmountOrCurrencyMismatchIsRefused(): void
    {
        $this->contracts->method('findById')->willReturn($this->pendingContract());
        $dispatcher = new ScriptedChainDispatcher();
        $service = $this->service($dispatcher);

        self::assertSame('amount_mismatch', $service->commit($this->confirmation(amount: 99.0))->reason);
        self::assertSame('amount_mismatch', $service->commit($this->confirmation(currency: 'USD'))->reason);
        self::assertSame([], $dispatcher->events);
    }

    public function testCurrencyIsComparedCaseInsensitivelyAndAmountWithinACent(): void
    {
        $this->contracts->method('findById')->willReturn($this->pendingContract());

        $outcome = $this->service(new ScriptedChainDispatcher())->commit(
            $this->confirmation(amount: 116.504, currency: 'eur')
        );

        self::assertSame(CommitOutcome::COMMITTED, $outcome->outcome);
    }

    public function testAPendingContractGoesThroughTheHandlerChainAndComesBackCommitted(): void
    {
        $this->contracts->method('findById')->willReturn($this->pendingContract());
        $dispatcher = new ScriptedChainDispatcher(['commit']);

        $outcome = $this->service($dispatcher)->commit($this->confirmation(extra: ['checkoutSessionId' => 'cs_1']));

        self::assertSame(CommitOutcome::COMMITTED, $outcome->outcome);
        self::assertSame('order-1', $outcome->orderId);
        self::assertTrue($outcome->isSettled());

        self::assertCount(1, $dispatcher->events);
        $event = $dispatcher->events[0];
        self::assertInstanceOf(PaymentAuthorizedEvent::class, $event);
        self::assertSame('auth-1', $event->getAuthorizationId());
        self::assertSame('pi_1', $event->getProviderOrderId());
        self::assertSame(116.5, $event->getAmount());
        self::assertSame('EUR', $event->getCurrency());
        $context = $event->getContext();
        self::assertSame('stripe', $context->get('providerName'));
        self::assertSame('contract-1', $context->get('contractId'));
        self::assertSame('contract-1', $context->get('contract_id'));
        self::assertFalse($context->get('requiresCapture'));
        self::assertSame('cs_1', $context->get('checkoutSessionId'));
        self::assertSame('webhook', $context->get('commitSource'));
    }

    /**
     * Another condition (fraud check) is still open: the chain fulfilled
     * ours but did not commit. Not a failure - the other condition's handler
     * will finish it - and the caller must not retry as if it were one.
     */
    public function testWhenTheChainLeavesTheContractOpenTheOutcomeIsPending(): void
    {
        $this->contracts->method('findById')->willReturn($this->pendingContract());

        $outcome = $this->service(new ScriptedChainDispatcher(['pending']))->commit($this->confirmation());

        self::assertSame(CommitOutcome::PENDING, $outcome->outcome);
        self::assertNull($outcome->orderId);
        self::assertFalse($outcome->isSettled());
    }

    /**
     * MOL-17 for the webhook side: the save was refused because the row moved
     * on - the return leg got there first. Reload; committed is the outcome
     * this call wanted.
     */
    public function testAStaleContractThatTurnsOutCommittedIsAlreadyProcessed(): void
    {
        $fresh = $this->pendingContract();
        $fresh->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $fresh->commitToOrder('order-1');
        $this->contracts->method('findById')->willReturnOnConsecutiveCalls($this->pendingContract(), $fresh);

        $outcome = $this->service(new ScriptedChainDispatcher(['stale']))->commit($this->confirmation());

        self::assertSame(CommitOutcome::ALREADY_PROCESSED, $outcome->outcome);
        self::assertSame('order-1', $outcome->orderId);
    }

    public function testAStaleContractStillOpenGetsTheChainOnceMoreOnTheFreshCopy(): void
    {
        $this->contracts->method('findById')->willReturnOnConsecutiveCalls($this->pendingContract(), $this->pendingContract());
        $dispatcher = new ScriptedChainDispatcher(['stale', 'commit']);

        $outcome = $this->service($dispatcher)->commit($this->confirmation());

        self::assertSame(CommitOutcome::COMMITTED, $outcome->outcome);
        self::assertCount(2, $dispatcher->events);
    }

    public function testAContractThatChangesTwiceIsLeftToTheOtherLeg(): void
    {
        $this->contracts->method('findById')->willReturnOnConsecutiveCalls($this->pendingContract(), $this->pendingContract());

        $outcome = $this->service(new ScriptedChainDispatcher(['stale', 'stale']))->commit($this->confirmation());

        self::assertSame(CommitOutcome::REFUSED, $outcome->outcome);
        self::assertSame('stale_contract', $outcome->reason);
    }

    private function service(EventDispatcherInterface $dispatcher): ContractCommitService
    {
        return new ContractCommitService($this->contracts, $dispatcher);
    }

    private function confirmation(float $amount = 116.5, string $currency = 'EUR', array $extra = []): PaymentConfirmation
    {
        return new PaymentConfirmation(
            contractId: 'contract-1',
            providerName: 'stripe',
            authorizationId: 'auth-1',
            providerOrderId: 'pi_1',
            amount: $amount,
            currency: $currency,
            requiresCapture: false,
            source: 'webhook',
            extraContext: $extra,
        );
    }

    private function pendingContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 116.5,
            'totalNet' => 97.9,
            'totalVat' => 18.6,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, 'contract-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();

        return $contract;
    }
}
