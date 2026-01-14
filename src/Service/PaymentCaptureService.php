<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use Psr\Log\LoggerInterface;
use DomainException;
use RuntimeException;

/**
 * Service for capturing authorized payments.
 *
 * Implements the capture operation following the contract-based payment architecture.
 * Validates contract state, calls provider API, and fulfills the contract.
 *
 * @see SPRINT-3-TICKET-13-capture-refund-operations.md
 */
class PaymentCaptureService
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly PaymentAdapterInterface $paymentAdapter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Capture an authorized payment.
     *
     * @param string $contractId The contract ID to capture
     * @param float|null $amount Optional partial amount to capture (null = full amount)
     * @return array{success: bool, captureId: string, amount: float} Capture result
     * @throws DomainException If contract validation fails
     * @throws RuntimeException If provider API fails
     */
    public function capturePayment(string $contractId, ?float $amount = null): array
    {
        // Load contract
        $contract = $this->contractRepository->findById($contractId);

        if (!$contract) {
            throw new DomainException("Contract not found: {$contractId}");
        }

        // Validate contract state
        if ($contract->getState()->isFulfilled()) {
            throw new DomainException('Payment already captured');
        }

        if (!$contract->getProviderOrderId()) {
            throw new DomainException('No authorization found for this contract');
        }

        if (!$contract->getState()->isCommitted()) {
            throw new DomainException('Contract must be committed before capture');
        }

        // Determine capture amount
        $captureAmount = $amount ?? $contract->getBasketSnapshot()->getTotalGross();

        try {
            // Call provider API
            $request = new CapturePaymentRequest(
                providerPaymentId: $contract->getProviderOrderId(),
                amount: $captureAmount
            );

            $response = $this->paymentAdapter->capturePayment($request);

            // Fulfill contract
            $contract->fulfill();
            $this->contractRepository->save($contract);

            // Log success
            $this->logger->info('Payment captured successfully', [
                'contractId' => $contractId,
                'amount' => $captureAmount,
                'providerOrderId' => $contract->getProviderOrderId(),
                'captureId' => $response->captureId,
            ]);

            return [
                'success' => true,
                'captureId' => $response->captureId,
                'amount' => $captureAmount,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Payment capture failed', [
                'contractId' => $contractId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Capture failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
