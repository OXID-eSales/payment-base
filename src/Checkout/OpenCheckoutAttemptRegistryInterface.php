<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

/**
 * Records which checkout attempt the current session has open.
 *
 * @since STRP-171
 */
interface OpenCheckoutAttemptRegistryInterface
{
    public function remember(string $contractId): void;

    /**
     * Returns the attempt this session had open, and forgets it.
     */
    public function takePrevious(): ?string;

    /**
     * Returns the attempt this session has open WITHOUT forgetting it - for a
     * caller that only asks whether one is in flight (MOL-18).
     */
    public function peek(): ?string;
}
