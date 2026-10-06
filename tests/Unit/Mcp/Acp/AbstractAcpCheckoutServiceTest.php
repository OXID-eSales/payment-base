<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Acp;

use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AbstractAcpCheckoutService;
use OxidEsales\PaymentBase\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentBase\Mcp\AgentContext;
use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractAcpCheckoutServiceTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private AcpResponseFormatterInterface&MockObject $formatter;
    private AgentContext $agentContext;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->formatter = $this->createMock(AcpResponseFormatterInterface::class);
        $this->agentContext = new AgentContext('agent_test', 'tok_test');
    }

    private function createService(): AbstractAcpCheckoutService
    {
        return new class (
            $this->contractService,
            $this->contractRepository,
            $this->eventDispatcher,
            $this->formatter
        ) extends AbstractAcpCheckoutService {
            public function createCheckout(array $arguments, AgentContextInterface $agentContext): array
            {
                return ['stub' => true];
            }

            protected function completePayment(
                PaymentContractInterface $contract,
                array $paymentData,
                AgentContextInterface $agentContext
            ): array {
                return ['order' => 'created'];
            }

            protected function paymentId(): string
            {
                return 'oe_payments_stub';
            }

            protected function providerName(): string
            {
                return 'stub';
            }
        };
    }

    /**
     * Sprint 15 / S7: a provider service that keeps the base class's default
     * createCheckout() and gets the headless collaborators.
     */
    private function createDefaultService(
        ?ContractOpeningServiceInterface $opening = null,
        ?UserBasketFactoryInterface $baskets = null,
        ?GuestUserResolverInterface $buyers = null,
        ?ContractCommitServiceInterface $commit = null,
    ): AbstractAcpCheckoutService {
        return new class (
            $this->contractService,
            $this->contractRepository,
            $this->eventDispatcher,
            $this->formatter,
            $opening,
            $baskets,
            $buyers,
            $commit
        ) extends AbstractAcpCheckoutService {
            protected function completePayment(
                PaymentContractInterface $contract,
                array $paymentData,
                AgentContextInterface $agentContext
            ): array {
                $outcome = $this->commitPaid($contract, 'auth-1', 'pi-1', 10.0, 'EUR');

                return ['outcome' => $outcome->outcome, 'orderId' => $outcome->orderId];
            }

            protected function paymentId(): string
            {
                return 'oe_payments_stub';
            }

            protected function providerName(): string
            {
                return 'stub';
            }
        };
    }

    public function testGetCheckoutReturnsFormattedContract(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contractRepository->method('findById')
            ->with('c_1')
            ->willReturn($contract);
        $this->formatter->expects($this->once())
            ->method('formatCheckout')
            ->with($contract)
            ->willReturn(['id' => 'c_1', 'status' => 'not_ready_for_payment']);

        $result = $this->createService()->getCheckout('c_1');

        $this->assertSame('c_1', $result['id']);
    }

    public function testGetCheckoutReturnsNotFoundForMissingContract(): void
    {
        $this->contractRepository->method('findById')->willReturn(null);
        $this->formatter->expects($this->once())
            ->method('notFoundError')
            ->with('missing_id')
            ->willReturn(['error' => ['type' => 'invalid_request']]);

        $result = $this->createService()->getCheckout('missing_id');

        $this->assertArrayHasKey('error', $result);
    }

    public function testUpdateCheckoutSetsMetadata(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->expects($this->atLeastOnce())->method('setMetadata');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->once())->method('save')->with($contract);
        $this->formatter->method('formatCheckout')->willReturn(['id' => 'c_1']);

        $this->createService()->updateCheckout('c_1', ['shipping' => 'express'], $this->agentContext);
    }

    public function testUpdateCheckoutSetsFulfillmentOption(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->expects($this->exactly(2))
            ->method('setMetadata');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->method('save');
        $this->formatter->method('formatCheckout')->willReturn(['id' => 'c_1']);

        $this->createService()->updateCheckout(
            'c_1',
            ['selected_fulfillment_option_id' => 'std_shipping'],
            $this->agentContext
        );
    }

    public function testCancelCheckoutCancelsNonTerminalContract(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->expects($this->once())->method('cancel');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->once())->method('save');
        $this->formatter->method('formatCheckout')->willReturn(['status' => 'canceled']);

        $result = $this->createService()->cancelCheckout('c_1');

        $this->assertSame('canceled', $result['status']);
    }

    public function testCancelCheckoutRejectsTerminalContract(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->expects($this->never())->method('cancel');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->formatter->method('validationError')
            ->willReturn(['error' => ['message' => 'terminal']]);

        $result = $this->createService()->cancelCheckout('c_1');

        $this->assertArrayHasKey('error', $result);
    }

    public function testCompleteCheckoutValidatesToken(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->formatter->expects($this->once())
            ->method('validationError')
            ->with('Payment token is required', 'payment_data.token')
            ->willReturn(['error' => ['message' => 'token required']]);

        $result = $this->createService()->completeCheckout('c_1', [], $this->agentContext);

        $this->assertArrayHasKey('error', $result);
    }

    public function testCompleteCheckoutDelegatesToCompletePayment(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->expects($this->atLeastOnce())->method('setMetadata');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->method('save');

        $result = $this->createService()->completeCheckout(
            'c_1',
            ['token' => 'spt_granted_123'],
            $this->agentContext
        );

        $this->assertSame(['order' => 'created'], $result);
    }

    public function testCompleteCheckoutRejectsTerminalContract(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->formatter->method('validationError')
            ->willReturn(['error' => ['message' => 'terminal']]);

        $result = $this->createService()->completeCheckout(
            'c_1',
            ['token' => 'tok'],
            $this->agentContext
        );

        $this->assertArrayHasKey('error', $result);
    }

    // ------------------------------------------------------------------
    // Sprint 15 / S7 — create_checkout on the headless path
    // ------------------------------------------------------------------

    public function testDefaultCreateCheckoutResolvesTheBuyerPersistsABasketAndOpensTheContract(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('c_1');
        $buyers = $this->createMock(GuestUserResolverInterface::class);
        $buyers->expects($this->once())->method('resolve')
            ->with(['email' => 'a@example.com'], ['line_one' => 'x', 'city' => 'y', 'postal_code' => '1', 'country' => 'DE'])
            ->willReturn('user-1');
        $baskets = $this->createMock(UserBasketFactoryInterface::class);
        $baskets->expects($this->once())->method('create')
            ->with('user-1', [['id' => 'art-1', 'quantity' => 2]], 'oe_payments_stub')
            ->willReturn('ub-1');
        $opening = $this->createMock(ContractOpeningServiceInterface::class);
        $opening->expects($this->once())->method('open')
            ->with('user-1', 'ub-1', 'oe_payments_stub', 'acp')
            ->willReturn($contract);
        $contract->expects($this->once())->method('setMetadata')->with('acp_agent_id', 'agent_test');
        $this->contractRepository->expects($this->once())->method('save')->with($contract);
        $this->formatter->method('formatCheckout')->with($contract)->willReturn(['id' => 'c_1', 'status' => 'ready_for_payment']);

        $result = $this->createDefaultService($opening, $baskets, $buyers)->createCheckout([
            'items' => [['id' => 'art-1', 'quantity' => 2]],
            'buyer' => ['email' => 'a@example.com'],
            'fulfillment_address' => ['line_one' => 'x', 'city' => 'y', 'postal_code' => '1', 'country' => 'DE'],
        ], $this->agentContext);

        $this->assertSame('c_1', $result['id']);
    }

    public function testDefaultCreateCheckoutValidatesItemsAndBuyer(): void
    {
        $this->formatter->method('validationError')->willReturnCallback(
            static fn(string $message, ?string $param = null): array => ['error' => ['message' => $message, 'param' => $param]]
        );
        $service = $this->createDefaultService(
            $this->createMock(ContractOpeningServiceInterface::class),
            $this->createMock(UserBasketFactoryInterface::class),
            $this->createMock(GuestUserResolverInterface::class)
        );

        $this->assertSame('items', $service->createCheckout(['buyer' => ['email' => 'a@example.com']], $this->agentContext)['error']['param']);
        $this->assertSame('buyer.email', $service->createCheckout(['items' => [['id' => 'a', 'quantity' => 1]], 'buyer' => []], $this->agentContext)['error']['param']);
    }

    public function testDefaultCreateCheckoutTranslatesAHeadlessRefusalIntoAValidationError(): void
    {
        $this->formatter->method('validationError')->willReturnCallback(
            static fn(string $message, ?string $param = null): array => ['error' => ['message' => $message, 'param' => $param]]
        );
        $buyers = $this->createMock(GuestUserResolverInterface::class);
        $buyers->method('resolve')->willReturn('user-1');
        $baskets = $this->createMock(UserBasketFactoryInterface::class);
        $baskets->method('create')->willReturn('ub-1');
        $opening = $this->createMock(ContractOpeningServiceInterface::class);
        $opening->method('open')->willThrowException(new HeadlessCheckoutException(HeadlessCheckoutException::BASKET_NOT_FOUND, 'Basket ub-1 not found'));

        $result = $this->createDefaultService($opening, $baskets, $buyers)->createCheckout([
            'items' => [['id' => 'art-1', 'quantity' => 1]],
            'buyer' => ['email' => 'a@example.com'],
        ], $this->agentContext);

        $this->assertSame('Basket ub-1 not found', $result['error']['message']);
    }

    public function testDefaultCreateCheckoutWithoutTheHeadlessCollaboratorsSaysSo(): void
    {
        $this->formatter->method('validationError')->willReturnCallback(
            static fn(string $message, ?string $param = null): array => ['error' => ['message' => $message]]
        );

        $result = $this->createDefaultService()->createCheckout([
            'items' => [['id' => 'art-1', 'quantity' => 1]],
            'buyer' => ['email' => 'a@example.com'],
        ], $this->agentContext);

        $this->assertStringContainsString('not wired', $result['error']['message']);
    }

    public function testCommitPaidHandsTheConfirmationToTheCommitService(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('c_1');
        $contract->method('getState')->willReturn(ContractState::pending());
        $this->contractRepository->method('findById')->willReturn($contract);
        $commit = $this->createMock(ContractCommitServiceInterface::class);
        $commit->expects($this->once())->method('commit')
            ->with($this->callback(function (PaymentConfirmation $c): bool {
                $this->assertSame('c_1', $c->contractId);
                $this->assertSame('stub', $c->providerName);
                $this->assertSame('auth-1', $c->authorizationId);
                $this->assertSame('pi-1', $c->providerOrderId);
                $this->assertSame(10.0, $c->amount);
                $this->assertSame('EUR', $c->currency);
                $this->assertSame('acp', $c->source);

                return true;
            }))
            ->willReturn(CommitOutcome::committed('order-1'));

        $result = $this->createDefaultService(commit: $commit)->completeCheckout('c_1', ['token' => 'spt_1', 'provider' => 'stub'], $this->agentContext);

        $this->assertSame(['outcome' => 'committed', 'orderId' => 'order-1'], $result);
    }
}
