<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Every provider creates its order BEFORE the shopper leaves for the PSP, so
 * that an order number exists to hand over. A shopper who retries - goes back,
 * refreshes, switches payment method - therefore leaves one NOT_FINISHED order
 * behind per attempt, and they accumulate in the backend with no payment date.
 *
 * Stripe has carried its own version of this cleanup since STRP-100. Mollie and
 * PayPal never had one, which is why the duplicates were reported against a
 * Mollie basket. This is that logic with nothing provider-specific left in it.
 *
 * @since STRP-171
 */
class PreviousCheckoutAttemptCleaner implements PreviousCheckoutAttemptCleanerInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopOrderServiceInterface $orderService,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function clean(?string $contractId): bool
    {
        if ($contractId === null || $contractId === '') {
            return false;
        }

        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            return false;
        }

        if (!$this->isAbandonable($contract, $contractId)) {
            return false;
        }

        $this->removeOrder($contract, $contractId);

        $contract->cancel('checkout_retry');
        $this->contractRepository->save($contract);

        return true;
    }

    private function isAbandonable(PaymentContractInterface $contract, string $contractId): bool
    {
        // `committed` is not terminal, and it means the money was taken.
        // Cancelling here would storno an order the shopper has paid for.
        if ($contract->getState()->isTerminal() || $contract->getState()->isCommitted()) {
            $this->logger->info('Previous checkout attempt left alone: it is already settled', [
                'contract_id' => $contractId,
                'state' => $contract->getStateValue(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * A contract that never got as far as an order has nothing to remove, and a
     * removal that fails must not stop the cancellation: leaving the contract
     * open would strand the next attempt behind a cleanup that cannot succeed.
     */
    private function removeOrder(PaymentContractInterface $contract, string $contractId): void
    {
        $orderId = $contract->getOrderId();

        if ($orderId === null || $orderId === '') {
            return;
        }

        try {
            $this->orderService->deleteNotFinishedOrder($orderId);
        } catch (Throwable $e) {
            $this->logger->error('Could not remove the order of a retried checkout attempt', [
                'contract_id' => $contractId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
