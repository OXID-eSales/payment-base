<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * One payment method, reduced to the two facts the single-payment decision
 * needs: how it is identified, and whether it collects data from the customer
 * on the payment step.
 *
 * Deliberately provider-agnostic and framework-free — an OXID core method
 * (oxidinvoice, oxidcashondel) becomes a candidate exactly like a PSP's.
 */
final class PaymentCandidate
{
    public function __construct(
        private readonly string $id,
        private readonly bool $requiresUserInput = false,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * True when the method asks the customer for data on the payment step
     * (OXID models this as the payment's dynamic values, e.g. the bank details
     * of oxiddebitnote). Such a method must never be assigned behind the
     * customer's back — the input fields live on the page we would skip.
     */
    public function requiresUserInput(): bool
    {
        return $this->requiresUserInput;
    }
}
