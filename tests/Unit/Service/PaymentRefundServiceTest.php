<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Service\PaymentRefundService;
use OxidEsales\PaymentComponent\Service\Exception\RefundFailedException;
use OxidEsales\PaymentComponent\Service\Result\RefundResult;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\PaymentComponent\Service\PaymentRefundService
 * @covers \OxidEsales\PaymentComponent\Service\AbstractPaymentRefundService
 */
class PaymentRefundServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private PaymentAdapterInterface&MockObject $paymentAdapter;
    private LoggerInterface&MockObject $logger;
    private PaymentRefundService $service;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->paymentAdapter = $this->createMock(PaymentAdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new PaymentRefundService(
            $this->contractRepository,
            $this->transactionRepository,
            $this->paymentAdapter,
            $this->logger
        );
    }

    // 1. Process full refund
    public function testProcessesFullRefund(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $capturedAmount = 100.00;

        $contract = $this->createMockContract($contractId, $providerOrderId, $capturedAmount, ContractState::fulfilled());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->transactionRepository
            ->expects($this->once())
            ->method('getTotalRefundedForContract')
            ->with($contractId)
            ->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_123', $capturedAmount);

        $this->paymentAdapter
            ->expects($this->once())
            ->method('refundPayment')
            ->with($this->callback(function (RefundPaymentRequest $request) use ($providerOrderId, $capturedAmount) {
                return $request->providerPaymentId === $providerOrderId
                    && $request->amount === $capturedAmount;
            }))
            ->willReturn($refundResponse);

        $this->transactionRepository
            ->expects($this->once())
            ->method('logRefund')
            ->with($contractId, $capturedAmount, 're_123', '');

        $result = $this->service->refund($contractId);

        $this->assertInstanceOf(RefundResult::class, $result);
        $this->assertEquals('re_123', $result->refundId);
        $this->assertEquals($capturedAmount, $result->amountRefunded);
        $this->assertEquals($capturedAmount, $result->totalRefunded);
        $this->assertEquals(0.00, $result->availableForRefund);
    }

    // 2. Process partial refund
    public function testProcessesPartialRefund(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $capturedAmount = 100.00;
        $partialAmount = 30.00;

        $contract = $this->createMockContract($contractId, $providerOrderId, $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_456', $partialAmount);
        $this->paymentAdapter->method('refundPayment')->willReturn($refundResponse);

        $result = $this->service->refund($contractId, $partialAmount);

        $this->assertInstanceOf(RefundResult::class, $result);
        $this->assertEquals($partialAmount, $result->amountRefunded);
        $this->assertEquals($partialAmount, $result->totalRefunded);
        $this->assertEquals(70.00, $result->availableForRefund);
    }

    // 3. Cannot refund uncaptured payment
    public function testCannotRefundUncapturedPayment(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::committed());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Can only refund fulfilled (captured) payments');

        $this->service->refund($contractId);
    }

    // 4. Cannot refund more than captured amount
    public function testCannotRefundMoreThanCaptured(): void
    {
        $contractId = 'contract123';
        $capturedAmount = 100.00;
        $requestedAmount = 150.00;

        $contract = $this->createMockContract($contractId, 'pi_123', $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Cannot refund 150.00. Available: 100.00');

        $this->service->refund($contractId, $requestedAmount);
    }

    // 5. Cannot refund already fully refunded payment
    public function testCannotRefundAlreadyRefunded(): void
    {
        $contractId = 'contract123';
        $capturedAmount = 100.00;

        $contract = $this->createMockContract($contractId, 'pi_123', $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository
            ->method('getTotalRefundedForContract')
            ->willReturn($capturedAmount); // Already fully refunded

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Refund amount must be positive');

        $this->service->refund($contractId);
    }

    // 6. Track multiple partial refunds
    public function testTracksMultiplePartialRefunds(): void
    {
        $contractId = 'contract123';
        $capturedAmount = 100.00;
        $firstRefund = 30.00;
        $alreadyRefunded = $firstRefund;

        $contract = $this->createMockContract($contractId, 'pi_123', $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository
            ->method('getTotalRefundedForContract')
            ->willReturn($alreadyRefunded); // €30 already refunded

        $secondRefund = 20.00;
        $refundResponse = $this->createRefundResponse('re_789', $secondRefund);
        $this->paymentAdapter->method('refundPayment')->willReturn($refundResponse);

        $result = $this->service->refund($contractId, $secondRefund);

        $this->assertInstanceOf(RefundResult::class, $result);
        $this->assertEquals($secondRefund, $result->amountRefunded);
        $this->assertEquals(50.00, $result->totalRefunded); // €30 + €20
        $this->assertEquals(50.00, $result->availableForRefund); // €100 - €50
    }

    // 7. Handle contract not found
    public function testHandlesContractNotFound(): void
    {
        $contractId = 'nonexistent';

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn(null);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Contract not found');

        $this->service->refund($contractId);
    }

    // 8. Handle provider API error
    public function testHandlesProviderApiError(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $this->paymentAdapter
            ->expects($this->once())
            ->method('refundPayment')
            ->willThrowException(new \Exception('Provider error: Refund not allowed'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Refund failed', $this->arrayHasKey('error'));

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Provider error: Refund not allowed');

        $this->service->refund($contractId);
    }

    // 9. Logs refund operation
    public function testLogsRefundOperation(): void
    {
        $contractId = 'contract123';
        $amount = 50.00;
        $reason = 'Customer requested refund';

        $contract = $this->createMockContract($contractId, 'pi_123', 100.00, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_123', $amount);
        $this->paymentAdapter->method('refundPayment')->willReturn($refundResponse);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Payment refunded successfully',
                $this->callback(function ($context) use ($contractId, $amount, $reason) {
                    return $context['contractId'] === $contractId
                        && $context['amount'] === $amount
                        && $context['reason'] === $reason;
                })
            );

        $this->service->refund($contractId, $amount, $reason);
    }

    // 10. Cannot refund without provider order ID
    public function testCannotRefundWithoutProviderOrderId(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getProviderOrderId')->willReturn(null);

        $this->contractRepository->method('findById')->willReturn($contract);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Cannot refund: Contract has no provider order ID');

        $this->service->refund($contractId);
    }

    // Helper methods

    private function createMockContract(
        string $id,
        string $providerOrderId,
        float $amount,
        ContractState $state
    ): PaymentContractInterface&MockObject {
        $contract = $this->createMock(PaymentContractInterface::class);

        $contract->method('getId')->willReturn($id);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getState')->willReturn($state);

        $basketSnapshot = $this->createMock(BasketSnapshot::class);
        $basketSnapshot->method('getTotalGross')->willReturn($amount);
        $basketSnapshot->method('getCurrency')->willReturn('EUR');

        $contract->method('getBasketSnapshot')->willReturn($basketSnapshot);

        return $contract;
    }

    private function createRefundResponse(string $refundId, float $amount): RefundResponse
    {
        return new RefundResponse(
            providerPaymentId: 'pi_test',
            refundId: $refundId,
            amountRefunded: $amount,
            currency: 'EUR',
            status: 'succeeded',
            refundedAt: new DateTimeImmutable()
        );
    }
}
