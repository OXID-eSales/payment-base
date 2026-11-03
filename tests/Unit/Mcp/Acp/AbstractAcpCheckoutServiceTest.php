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
            $this->formatter,
            $this->createMock(ContractOpeningServiceInterface::class),
            $this->createMock(UserBasketFactoryInterface::class),
            $this->createMock(GuestUserResolverInterface::class),
            $this->createMock(ContractCommitServiceInterface::class)
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
        $opening ??= $this->createMock(ContractOpeningServiceInterface::class);
        $baskets ??= $this->createMock(UserBasketFactoryInterface::class);
        $buyers ??= $this->createMock(GuestUserResolverInterface::class);
        $commit ??= $this->createMock(ContractCommitServiceInterface::class);

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

    /**
     * A contract the agent opened over ACP: channel `acp`, `acp_agent_id` its own.
     */
    private function contractOf(string $agentId, string $channel = 'acp', bool $terminal = false): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn($terminal);
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getMetadata')->willReturnMap([
            ['channel', $channel],
            ['acp_agent_id', $agentId],
        ]);

        return $contract;
    }

    /**
     * VR-24 H8 / S7: a contract the agent did not open over ACP is
     * indistinguishable from a missing one - nothing is read, changed,
     * cancelled or charged, and nothing tells the agent the id exists.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function foreignContracts(): iterable
    {
        yield 'another agent\'s checkout' => ['agent_other', 'acp'];
        yield 'a storefront contract' => ['agent_test', 'storefront'];
        yield 'a contract with no agent' => ['', 'acp'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignContracts')]
    public function testForeignAgentCannotReadContract(string $agentId, string $channel): void
    {
        $this->contractRepository->method('findById')->willReturn($this->contractOf($agentId, $channel));
        $this->formatter->expects($this->never())->method('formatCheckout');
        $this->formatter->expects($this->once())->method('notFoundError')->with('c_1')->willReturn(['error' => 'not_found']);

        $this->assertSame(['error' => 'not_found'], $this->createService()->getCheckout('c_1', $this->agentContext));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignContracts')]
    public function testForeignAgentCannotUpdateContract(string $agentId, string $channel): void
    {
        $contract = $this->contractOf($agentId, $channel);
        $contract->expects($this->never())->method('setMetadata');
        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->never())->method('save');
        $this->formatter->method('notFoundError')->willReturn(['error' => 'not_found']);

        $this->assertSame(
            ['error' => 'not_found'],
            $this->createService()->updateCheckout('c_1', ['shipping' => 'express'], $this->agentContext)
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignContracts')]
    public function testForeignAgentCannotCancelContract(string $agentId, string $channel): void
    {
        $contract = $this->contractOf($agentId, $channel);
        $contract->expects($this->never())->method('cancel');
        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->never())->method('save');
        $this->formatter->method('notFoundError')->willReturn(['error' => 'not_found']);

        $this->assertSame(['error' => 'not_found'], $this->createService()->cancelCheckout('c_1', $this->agentContext));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignContracts')]
    public function testForeignAgentCannotCompleteContractAndDoesNotBecomeItsOwner(string $agentId, string $channel): void
    {
        $contract = $this->contractOf($agentId, $channel);
        $contract->expects($this->never())->method('setMetadata');
        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->never())->method('save');
        $this->formatter->method('notFoundError')->willReturn(['error' => 'not_found']);

        $this->assertSame(
            ['error' => 'not_found'],
            $this->createService()->completeCheckout('c_1', ['token' => 'spt_1'], $this->agentContext)
        );
    }

    public function testUpdateCheckoutRefusesKeysOutsideTheAllowList(): void
    {
        $contract = $this->contractOf('agent_test');
        $contract->expects($this->never())->method('setMetadata');
        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->never())->method('save');
        $this->formatter->expects($this->once())->method('validationError')
            ->with('Field "agent_id" cannot be updated', 'agent_id')
            ->willReturn(['error' => 'refused']);

        $result = $this->createService()->updateCheckout(
            'c_1',
            ['shipping' => 'express', 'agent_id' => 'agent_other'],
            $this->agentContext
        );

        $this->assertSame(['error' => 'refused'], $result);
    }

    public function testUpdateCheckoutRefusesATerminalContract(): void
    {
        $contract = $this->contractOf('agent_test', terminal: true);
        $contract->expects($this->never())->method('setMetadata');
        $this->contractRepository->method('findById')->willReturn($contract);
        $this->formatter->expects($this->once())->method('validationError')->willReturn(['error' => 'terminal']);

        $this->assertSame(
            ['error' => 'terminal'],
            $this->createService()->updateCheckout('c_1', ['shipping' => 'express'], $this->agentContext)
        );
    }

    public function testGetCheckoutReturnsFormattedContract(): void
    {
        $contract = $this->contractOf('agent_test');
        $this->contractRepository->method('findById')
            ->with('c_1')
            ->willReturn($contract);
        $this->formatter->expects($this->once())
            ->method('formatCheckout')
            ->with($contract)
            ->willReturn(['id' => 'c_1', 'status' => 'not_ready_for_payment']);

        $result = $this->createService()->getCheckout('c_1', $this->agentContext);

        $this->assertSame('c_1', $result['id']);
    }

    public function testGetCheckoutReturnsNotFoundForMissingContract(): void
    {
        $this->contractRepository->method('findById')->willReturn(null);
        $this->formatter->expects($this->once())
            ->method('notFoundError')
            ->with('missing_id')
            ->willReturn(['error' => ['type' => 'invalid_request']]);

        $result = $this->createService()->getCheckout('missing_id', $this->agentContext);

        $this->assertArrayHasKey('error', $result);
    }

    public function testUpdateCheckoutSetsMetadata(): void
    {
        $contract = $this->contractOf('agent_test');
        $contract->expects($this->atLeastOnce())->method('setMetadata');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->once())->method('save')->with($contract);
        $this->formatter->method('formatCheckout')->willReturn(['id' => 'c_1']);

        $this->createService()->updateCheckout('c_1', ['shipping' => 'express'], $this->agentContext);
    }

    public function testUpdateCheckoutSetsFulfillmentOption(): void
    {
        $contract = $this->contractOf('agent_test');
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
        $contract = $this->contractOf('agent_test');
        $contract->expects($this->once())->method('cancel');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->once())->method('save');
        $this->formatter->method('formatCheckout')->willReturn(['status' => 'canceled']);

        $result = $this->createService()->cancelCheckout('c_1', $this->agentContext);

        $this->assertSame('canceled', $result['status']);
    }

    public function testCancelCheckoutRejectsTerminalContract(): void
    {
        $contract = $this->contractOf('agent_test', terminal: true);
        $contract->expects($this->never())->method('cancel');

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->formatter->method('validationError')
            ->willReturn(['error' => ['message' => 'terminal']]);

        $result = $this->createService()->cancelCheckout('c_1', $this->agentContext);

        $this->assertArrayHasKey('error', $result);
    }

    public function testCompleteCheckoutValidatesToken(): void
    {
        $contract = $this->contractOf('agent_test');

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
        $contract = $this->contractOf('agent_test');
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
        $contract = $this->contractOf('agent_test', terminal: true);

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

    public function testCommitPaidHandsTheConfirmationToTheCommitService(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('c_1');
        $contract->method('getState')->willReturn(ContractState::pending());
        $contract->method('getMetadata')->willReturnMap([['channel', 'acp'], ['acp_agent_id', 'agent_test']]);
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
