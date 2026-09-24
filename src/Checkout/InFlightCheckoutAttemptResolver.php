<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * "Order now" clicked twice: the second submission must not start a second
 * attempt (a second contract, a second order, a second PSP payment) - it must
 * rejoin the first. The state machine already says what "in flight" means:
 *
 *  - this session remembers an open attempt ({@see OpenCheckoutAttemptRegistry}),
 *  - its contract is neither terminal nor committed (no money has moved),
 *  - the PSP already handed us a checkout URL for it,
 *  - the order it created still sits at NOT_FINISHED,
 *  - and the basket has not changed in between (same gross total).
 *
 * Anything else means "not in flight": the caller proceeds as today, which
 * retires the old attempt and creates a new one.
 *
 * No time window: a Mollie checkout URL that expired meanwhile shows Mollie's
 * own "expired" page, and the return leg then retires the attempt as usual.
 *
 * @since MOL-18
 */
class InFlightCheckoutAttemptResolver implements InFlightCheckoutAttemptResolverInterface
{
    /**
     * Gross totals are money values that went through float arithmetic on
     * both sides; anything below half a cent is the same amount.
     */
    private const AMOUNT_TOLERANCE = 0.005;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly OpenCheckoutAttemptRegistryInterface $openAttempts,
        private readonly ContractRepositoryInterface $contracts,
        private readonly NotFinishedOrderRepositoryInterface $orders,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function resolve(float $liveBasketTotal): ?string
    {
        $contractId = $this->openAttempts->peek();
        if ($contractId === null) {
            return null;
        }

        $contract = $this->contracts->findById($contractId);
        $reason = $contract === null ? 'contract not found' : $this->whyNotInFlight($contract, $liveBasketTotal);
        if ($contract === null || $reason !== null) {
            $this->logNotInFlight($contractId, (string) $reason);

            return null;
        }

        $this->logger->info('Checkout attempt is in flight; replaying its checkout redirect', [
            'contract_id' => $contractId,
            'order_id' => $contract->getOrderId(),
        ]);

        return $contract->getProviderRedirectUrl();
    }

    /**
     * @return string|null the reason this attempt cannot be rejoined, or null when it can
     */
    private function whyNotInFlight(PaymentContractInterface $contract, float $liveBasketTotal): ?string
    {
        $state = $contract->getState();
        if ($state->isTerminal() || $state->isCommitted()) {
            return 'contract is ' . $state->getValue();
        }

        $redirectUrl = $contract->getProviderRedirectUrl();
        if ($redirectUrl === null || $redirectUrl === '') {
            return 'no checkout url yet';
        }

        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '' || !$this->orders->isNotFinished($orderId)) {
            return 'order is not NOT_FINISHED';
        }

        if (abs($contract->getBasketSnapshot()->getTotalGross() - $liveBasketTotal) >= self::AMOUNT_TOLERANCE) {
            return 'basket total changed';
        }

        return null;
    }

    private function logNotInFlight(string $contractId, string $reason): void
    {
        $this->logger->info('Open checkout attempt is not in flight; a new attempt is due', [
            'contract_id' => $contractId,
            'reason' => $reason,
        ]);
    }
}
