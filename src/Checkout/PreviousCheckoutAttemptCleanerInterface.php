<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

/**
 * Retires the checkout attempt a shopper left behind before a new one is made.
 *
 * @since STRP-171
 */
interface PreviousCheckoutAttemptCleanerInterface
{
    /**
     * Cancel the contract and remove the NOT_FINISHED order it created.
     *
     * @param string|null $contractId the attempt to retire, or null for none
     *
     * @return bool whether an attempt was actually retired
     */
    public function clean(?string $contractId): bool;
}
