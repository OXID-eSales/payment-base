<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Math\Money\MinorUnitConverter;
use OxidEsales\PaymentBase\Math\Money\Money;

/**
 * What the admin API knows of an order's payment, and what may still be done with it: the one place that
 * decides how much is capturable and refundable, so the API's checks and the screen's bounds cannot drift apart.
 *
 * Capturable: the contract is authorised, or committed by a manual-capture provider, and nothing was recorded
 * as captured yet (providers record the capture on the contract the moment they take the money). The
 * provider validates again; this is the bound the admin may offer.
 *
 * @since 1.5.0
 */
final readonly class PaymentContractDetails
{
    private const CAPTURABLE_STATES = ['authorized', 'committed'];
    private const REFUNDABLE_STATES = ['committed', 'fulfilled'];

    /**
     * @param list<PaymentTransactionDetails> $transactions
     */
    public function __construct(
        public string $contractId,
        public string $provider,
        public string $state,
        public float $amountAuthorised,
        public float $amountCaptured,
        public float $amountRefunded,
        public string $currency,
        public array $transactions,
    ) {
    }

    public function amountCapturable(): float
    {
        if (!in_array($this->state, self::CAPTURABLE_STATES, true) || $this->amountCaptured > Money::HALF_CENT_EPSILON) {
            return 0.0;
        }

        return $this->amountAuthorised;
    }

    public function amountRefundable(): float
    {
        if (!in_array($this->state, self::REFUNDABLE_STATES, true)) {
            return 0.0;
        }
        $rest = $this->amountCaptured - $this->amountRefunded;

        return $rest < Money::HALF_CENT_EPSILON ? 0.0 : $rest;
    }

    /**
     * The amount as the provider sees it: rounded to the minor unit of the order's currency.
     */
    public function roundToMinorUnit(float $amount): float
    {
        return MinorUnitConverter::toMajorUnits(MinorUnitConverter::toMinorUnits($amount, $this->currency), $this->currency);
    }

    /**
     * Changes whenever the money or the lifecycle moved: how a caller tells that a provider action did something.
     */
    public function fingerprint(): string
    {
        return sprintf('%s|%.4f|%.4f', $this->state, $this->amountCaptured, $this->amountRefunded);
    }
}
