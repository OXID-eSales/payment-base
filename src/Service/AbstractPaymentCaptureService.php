<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\Exception\CaptureFailedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Abstract service for capturing authorized payments.
 *
 * Sprint 3: Template Method pattern - provides common capture logic,
 * allowing providers to customize state validation and post-capture behavior.
 *
 * Hook methods:
 * - validateStateForCapture(): Override to customize valid states (default: COMMITTED)
 * - afterCapture(): Override for post-capture actions (default: fulfill contract)
 *
 * @since 1.0.0
 */
abstract class AbstractPaymentCaptureService
{
    public function __construct(
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly PaymentAdapterInterface $paymentAdapter,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Capture an authorized payment.
     *
     * Sprint 31: Returns CaptureResponse directly (no Result wrapper).
     *
     * @param string $contractId The contract ID to capture
     * @param float|null $amount Optional partial amount to capture (null = full amount)
     * @return CaptureResponse Capture response
     * @throws CaptureFailedException If capture fails
     */
    final public function capture(string $contractId, ?float $amount = null): CaptureResponse
    {
        $contract = $this->loadContract($contractId);
        $this->validateContract($contract);
        $this->validateStateForCapture($contract);

        $captureAmount = $this->determineCaptureAmount($contract, $amount);

        try {
            $response = $this->executeCapture($contract, $captureAmount);

            $this->afterCapture($contract, $response);

            $this->logger->info('Payment captured successfully', [
                'contractId' => $contractId,
                'amount' => $captureAmount,
                'captureId' => $response->captureId,
            ]);

            return $response;
        } catch (CaptureFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Payment capture failed', [
                'contractId' => $contractId,
                'error' => $e->getMessage(),
            ]);

            throw new CaptureFailedException($contractId, $e->getMessage(), $e);
        }
    }

    /**
     * Load contract from repository.
     *
     * @throws CaptureFailedException If contract not found
     */
    protected function loadContract(string $contractId): PaymentContractInterface
    {
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            throw new CaptureFailedException($contractId, 'Contract not found');
        }

        return $contract;
    }

    /**
     * Validate contract has required data for capture.
     *
     * @throws CaptureFailedException If validation fails
     */
    protected function validateContract(PaymentContractInterface $contract): void
    {
        if ($contract->getState()->isFulfilled()) {
            throw new CaptureFailedException(
                $contract->getId() ?? 'unknown',
                'Payment already captured'
            );
        }

        if ($contract->getProviderOrderId() === null) {
            throw new CaptureFailedException(
                $contract->getId() ?? 'unknown',
                'No authorization found for this contract'
            );
        }
    }

    /**
     * Validate contract state for capture.
     *
     * Default: Contract must be in COMMITTED state.
     * Override in providers that use different state flows (e.g., AUTHORIZED for delayed capture).
     *
     * @throws CaptureFailedException If state is invalid
     */
    protected function validateStateForCapture(PaymentContractInterface $contract): void
    {
        if (!$contract->getState()->isCommitted()) {
            throw new CaptureFailedException(
                $contract->getId() ?? 'unknown',
                'Contract must be committed before capture'
            );
        }
    }

    /**
     * Determine the amount to capture.
     */
    protected function determineCaptureAmount(PaymentContractInterface $contract, ?float $amount): float
    {
        return $amount ?? $contract->getBasketSnapshot()->getTotalGross();
    }

    /**
     * Execute the capture via payment adapter.
     */
    protected function executeCapture(PaymentContractInterface $contract, float $amount): CaptureResponse
    {
        // Validated non-null in validateContract()
        /** @var string $providerOrderId */
        $providerOrderId = $contract->getProviderOrderId();

        $request = new CapturePaymentRequest(
            providerPaymentId: $providerOrderId,
            amount: $amount
        );

        return $this->paymentAdapter->capturePayment($request);
    }

    /**
     * Post-capture hook.
     *
     * Default: Fulfill contract and save.
     * Override in providers that need different post-capture behavior.
     */
    protected function afterCapture(PaymentContractInterface $contract, CaptureResponse $response): void
    {
        $contract->fulfill();
        $this->contractRepository->save($contract);
    }
}
