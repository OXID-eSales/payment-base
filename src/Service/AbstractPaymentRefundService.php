<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentComponent\Service\Exception\RefundFailedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Abstract service for refunding captured payments.
 *
 * Sprint 3: Template Method pattern - provides common refund logic,
 * allowing providers to customize state validation and post-refund behavior.
 *
 * Hook methods:
 * - validateStateForRefund(): Override to customize valid states (default: FULFILLED)
 * - afterRefund(): Override for post-refund actions (default: log to transaction repository)
 *
 * @since 1.0.0
 */
abstract class AbstractPaymentRefundService
{
    public function __construct(
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly TransactionRepositoryInterface $transactionRepository,
        protected readonly PaymentAdapterInterface $paymentAdapter,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Refund a captured payment (full or partial).
     *
     * Sprint 31: Returns array with RefundResponse and business fields.
     *
     * @param string $contractId The contract ID to refund
     * @param float|null $amount Optional partial amount to refund (null = full remaining amount)
     * @param string $reason Optional reason for the refund
     * @return array{response: RefundResponse, totalRefunded: float, availableForRefund: float}
     * @throws RefundFailedException If refund fails
     */
    public function refund(string $contractId, ?float $amount = null, string $reason = ''): array
    {
        $contract = $this->loadContract($contractId);
        $this->validateStateForRefund($contract);
        $this->validateProviderOrderId($contract);

        $refundAmounts = $this->calculateRefundAmounts($contract, $amount);
        $this->validateRefundAmount($contractId, $refundAmounts['refundAmount'], $refundAmounts['availableForRefund']);

        try {
            $response = $this->executeRefund($contract, $refundAmounts['refundAmount'], $reason);

            $this->afterRefund($contract, $response, $reason);

            $newTotalRefunded = $refundAmounts['alreadyRefunded'] + $refundAmounts['refundAmount'];
            $newAvailableForRefund = $refundAmounts['totalCaptured'] - $newTotalRefunded;

            $this->logger->info('Payment refunded successfully', [
                'contractId' => $contractId,
                'amount' => $refundAmounts['refundAmount'],
                'refundId' => $response->refundId,
                'reason' => $reason,
                'totalRefunded' => $newTotalRefunded,
            ]);

            return [
                'response' => $response,
                'totalRefunded' => $newTotalRefunded,
                'availableForRefund' => $newAvailableForRefund,
            ];
        } catch (RefundFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Refund failed', [
                'contractId' => $contractId,
                'error' => $e->getMessage(),
            ]);

            throw new RefundFailedException($contractId, $e->getMessage(), $e);
        }
    }

    /**
     * Load contract from repository.
     *
     * @throws RefundFailedException If contract not found
     */
    protected function loadContract(string $contractId): PaymentContractInterface
    {
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            throw new RefundFailedException($contractId, 'Contract not found');
        }

        return $contract;
    }

    /**
     * Validate contract state for refund.
     *
     * Default: Contract must be in FULFILLED state.
     * Override in providers that use different state flows.
     *
     * @throws RefundFailedException If state is invalid
     */
    protected function validateStateForRefund(PaymentContractInterface $contract): void
    {
        if (!$contract->getState()->isFulfilled()) {
            throw new RefundFailedException(
                $contract->getId() ?? 'unknown',
                'Can only refund fulfilled (captured) payments'
            );
        }
    }

    /**
     * Validate contract has provider order ID.
     *
     * @throws RefundFailedException If no provider order ID
     */
    protected function validateProviderOrderId(PaymentContractInterface $contract): void
    {
        if ($contract->getProviderOrderId() === null) {
            throw new RefundFailedException(
                $contract->getId() ?? 'unknown',
                'Cannot refund: Contract has no provider order ID'
            );
        }
    }

    /**
     * Calculate refund amounts.
     *
     * @return array{totalCaptured: float, alreadyRefunded: float, availableForRefund: float, refundAmount: float}
     */
    protected function calculateRefundAmounts(PaymentContractInterface $contract, ?float $amount): array
    {
        $contractId = $contract->getId() ?? 'unknown';
        $totalCaptured = $contract->getBasketSnapshot()->getTotalGross();
        $alreadyRefunded = $this->transactionRepository->getTotalRefundedForContract($contractId);
        $availableForRefund = $totalCaptured - $alreadyRefunded;

        return [
            'totalCaptured' => $totalCaptured,
            'alreadyRefunded' => $alreadyRefunded,
            'availableForRefund' => $availableForRefund,
            'refundAmount' => $amount ?? $availableForRefund,
        ];
    }

    /**
     * Validate refund amount.
     *
     * @throws RefundFailedException If amount is invalid
     */
    protected function validateRefundAmount(string $contractId, float $refundAmount, float $availableForRefund): void
    {
        if ($refundAmount > $availableForRefund) {
            throw new RefundFailedException(
                $contractId,
                sprintf('Cannot refund %.2f. Available: %.2f', $refundAmount, $availableForRefund)
            );
        }

        if ($refundAmount <= 0) {
            throw new RefundFailedException(
                $contractId,
                'Refund amount must be positive'
            );
        }
    }

    /**
     * Execute the refund via payment adapter.
     */
    protected function executeRefund(
        PaymentContractInterface $contract,
        float $amount,
        string $reason
    ): RefundResponse {
        // Validated non-null in validateProviderOrderId()
        /** @var string $providerOrderId */
        $providerOrderId = $contract->getProviderOrderId();

        $request = new RefundPaymentRequest(
            providerPaymentId: $providerOrderId,
            amount: $amount,
            reason: $reason
        );

        return $this->paymentAdapter->refundPayment($request);
    }

    /**
     * Post-refund hook.
     *
     * Default: Log refund to transaction repository.
     * Override in providers that need different post-refund behavior.
     */
    protected function afterRefund(
        PaymentContractInterface $contract,
        RefundResponse $response,
        string $reason
    ): void {
        // Only log if we have valid refund data
        if ($response->amountRefunded !== null && $response->refundId !== null) {
            $this->transactionRepository->logRefund(
                $contract->getId() ?? 'unknown',
                $response->amountRefunded,
                $response->refundId,
                $reason
            );
        }
    }
}
