<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
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
    /**
     * Core generates this once on the order page and uses it as the id of the
     * order finalizeOrder() creates; it is only deleted on the thank-you page.
     */
    public const SESSION_CHALLENGE = 'sess_challenge';

    private readonly LoggerInterface $logger;

    /**
     * The session is optional so a consumer whose services.yaml predates
     * MOL-18 keeps working - it simply does not get the challenge rotation.
     */
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopOrderServiceInterface $orderService,
        ?LoggerInterface $logger = null,
        private readonly ?SessionAdapterInterface $session = null
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
        $this->forgetSessionChallenge($contract);

        return true;
    }

    /**
     * MOL-18: the retired order keeps its row (storno, CANCELLED - the number
     * sequence must stay gap-free), so as long as `sess_challenge` still names
     * it, core's finalizeOrder() answers ORDEREXISTS for every further attempt
     * in this session and no new order can ever be created. Before 2026-09-24
     * that was masked by OxidShopOrderService saving a phantom row. Forgetting
     * the challenge makes core issue a fresh one on the next order page, or a
     * fresh id inside finalizeOrder() when the retry is already under way.
     *
     * Only a challenge naming THIS attempt's order is touched: a newer one
     * belongs to whatever attempt the shopper is on now.
     */
    private function forgetSessionChallenge(PaymentContractInterface $contract): void
    {
        if ($this->session === null) {
            return;
        }

        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        if ($this->session->getVariable(self::SESSION_CHALLENGE) !== $orderId) {
            return;
        }

        $this->session->setVariable(self::SESSION_CHALLENGE, null);
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
