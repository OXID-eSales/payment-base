<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\PaymentCaptureService;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use DomainException;
use RuntimeException;

class PaymentCaptureServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentAdapterInterface&MockObject $paymentAdapter;
    private LoggerInterface&MockObject $logger;
    private PaymentCaptureService $service;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->paymentAdapter = $this->createMock(PaymentAdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new PaymentCaptureService(
            $this->contractRepository,
            $this->paymentAdapter,
            $this->logger
        );
    }

    // 1. Capture full authorized amount
    public function testCapturesFullAmount(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $amount = 99.99;

        $contract = $this->createMockContract($contractId, $providerOrderId, $amount, ContractState::committed());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $captureResponse = $this->createMockCaptureResponse('ch_123', $amount);

        $this->paymentAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) use ($providerOrderId, $amount) {
                return $request->providerPaymentId === $providerOrderId
                    && $request->amount === $amount;
            }))
            ->willReturn($captureResponse);

        $contract->expects($this->once())
            ->method('fulfill');

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Payment captured successfully', $this->arrayHasKey('contractId'));

        $result = $this->service->capturePayment($contractId);

        $this->assertTrue($result['success']);
        $this->assertEquals('ch_123', $result['captureId']);
        $this->assertEquals($amount, $result['amount']);
    }

    // 2. Capture partial amount
    public function testCapturesPartialAmount(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $authorizedAmount = 100.00;
        $partialAmount = 50.00;

        $contract = $this->createMockContract($contractId, $providerOrderId, $authorizedAmount, ContractState::committed());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $captureResponse = $this->createMockCaptureResponse('ch_456', $partialAmount);

        $this->paymentAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) use ($partialAmount) {
                return $request->amount === $partialAmount;
            }))
            ->willReturn($captureResponse);

        $contract->expects($this->once())
            ->method('fulfill');

        $result = $this->service->capturePayment($contractId, $partialAmount);

        $this->assertTrue($result['success']);
        $this->assertEquals($partialAmount, $result['amount']);
    }

    // 3. Cannot capture already fulfilled contract
    public function testCannotCaptureAlreadyFulfilled(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::fulfilled());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Payment already captured');

        $this->service->capturePayment($contractId);
    }

    // 4. Cannot capture without authorization
    public function testCannotCaptureWithoutAuthorization(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);
        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->method('getProviderOrderId')->willReturn(null);

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No authorization found for this contract');

        $this->service->capturePayment($contractId);
    }

    // 5. Cannot capture uncommitted contract
    public function testCannotCaptureUncommittedContract(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::pending());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contract must be committed before capture');

        $this->service->capturePayment($contractId);
    }

    // 6. Handle contract not found
    public function testHandlesContractNotFound(): void
    {
        $contractId = 'nonexistent';

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn(null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contract not found: nonexistent');

        $this->service->capturePayment($contractId);
    }

    // 7. Handle provider API error
    public function testHandlesProviderApiError(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';

        $contract = $this->createMockContract($contractId, $providerOrderId, 99.99, ContractState::committed());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->paymentAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willThrowException(new \Exception('Provider error: Insufficient funds'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Payment capture failed', $this->arrayHasKey('error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Capture failed: Provider error: Insufficient funds');

        $this->service->capturePayment($contractId);
    }

    // 8. Logs capture operation
    public function testLogsCaptureOperation(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $amount = 99.99;

        $contract = $this->createMockContract($contractId, $providerOrderId, $amount, ContractState::committed());

        $this->contractRepository->method('findById')->willReturn($contract);

        $captureResponse = $this->createMockCaptureResponse('ch_123', $amount);
        $this->paymentAdapter->method('capturePayment')->willReturn($captureResponse);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Payment captured successfully',
                $this->callback(function ($context) use ($contractId, $amount, $providerOrderId) {
                    return $context['contractId'] === $contractId
                        && $context['amount'] === $amount
                        && $context['providerOrderId'] === $providerOrderId;
                })
            );

        $this->service->capturePayment($contractId);
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

    private function createMockCaptureResponse(string $captureId, float $amount): CaptureResponse
    {
        return new CaptureResponse(
            providerPaymentId: 'pi_test',
            captureId: $captureId,
            amountCaptured: $amount,
            currency: 'EUR',
            status: 'succeeded',
            capturedAt: new \DateTime()
        );
    }
}
