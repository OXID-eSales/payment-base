<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Mcp\Acp;

use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AbstractAcpCheckoutService;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
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
            public function createCheckout(array $arguments, AgentContext $agentContext): array
            {
                return ['stub' => true];
            }

            protected function completePayment(
                PaymentContractInterface $contract,
                array $paymentData,
                AgentContext $agentContext
            ): array {
                return ['order' => 'created'];
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
}
