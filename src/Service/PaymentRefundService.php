<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use Psr\Log\LoggerInterface;
use DomainException;
use RuntimeException;

/**
 * Service for refunding captured payments.
 *
 * Implements the refund operation following the contract-based payment architecture.
 * Supports full and partial refunds, tracks refund history, and validates refund limits.
 *
 * @see SPRINT-3-TICKET-13-capture-refund-operations.md
 */
class PaymentRefundService
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly PaymentAdapterInterface $paymentAdapter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Refund a captured payment (full or partial).
     *
     * @param string $contractId The contract ID to refund
     * @param float|null $amount Optional partial amount to refund (null = full refund)
     * @param string $reason Optional reason for the refund
     * @return array{success: bool, refundId: string, amount: float, totalRefunded: float, availableForRefund: float}
     * @throws DomainException If contract validation fails
     * @throws RuntimeException If provider API fails
     */
    public function refundPayment(string $contractId, ?float $amount = null, string $reason = ''): array
    {
        // Load contract
        $contract = $this->contractRepository->findById($contractId);

        if (!$contract) {
            throw new DomainException("Contract not found: {$contractId}");
        }

        // Validate contract state
        if (!$contract->getState()->isFulfilled()) {
            throw new DomainException('Can only refund fulfilled (captured) payments');
        }

        // Calculate refund amounts
        $totalCaptured = $contract->getBasketSnapshot()->getTotalGross();
        $alreadyRefunded = $this->transactionRepository->getTotalRefundedForContract($contractId);
        $availableForRefund = $totalCaptured - $alreadyRefunded;

        // Determine refund amount
        $refundAmount = $amount ?? $availableForRefund;

        // Validate refund amount
        if ($refundAmount > $availableForRefund) {
            throw new DomainException(
                "Cannot refund {$refundAmount}. Available: {$availableForRefund}"
            );
        }

        if ($refundAmount <= 0) {
            throw new DomainException('Refund amount must be positive');
        }

        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null) {
            throw new DomainException('Cannot refund: Contract has no provider order ID');
        }

        try {
            // Call provider API
            $request = new RefundPaymentRequest(
                providerPaymentId: $providerOrderId,
                amount: $refundAmount,
                reason: $reason
            );

            $response = $this->paymentAdapter->refundPayment($request);

            // Log refund
            $this->transactionRepository->logRefund(
                $contractId,
                $refundAmount,
                $response->refundId,
                $reason
            );

            // Calculate totals
            $newTotalRefunded = $alreadyRefunded + $refundAmount;
            $newAvailableForRefund = $totalCaptured - $newTotalRefunded;

            // Log success
            $this->logger->info('Payment refunded successfully', [
                'contractId' => $contractId,
                'amount' => $refundAmount,
                'refundId' => $response->refundId,
                'reason' => $reason,
                'totalRefunded' => $newTotalRefunded,
            ]);

            return [
                'success' => true,
                'refundId' => $response->refundId,
                'amount' => $refundAmount,
                'totalRefunded' => $newTotalRefunded,
                'availableForRefund' => $newAvailableForRefund,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Refund failed', [
                'contractId' => $contractId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Refund failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
