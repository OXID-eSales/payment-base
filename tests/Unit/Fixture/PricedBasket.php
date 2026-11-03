<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Fixture;

use stdClass;

/**
 * What ContractService reads from an OXID basket for the contract totals: the
 * price (gross / net / VAT) and the basket currency. Either can be missing, to
 * prove the contract refuses to guess them.
 */
final class PricedBasket
{
    public function __construct(
        private readonly ?string $currency = 'EUR',
        private readonly bool $withPrice = true,
        private readonly float $gross = 100.0,
        private readonly float $net = 84.03,
        private readonly float $vat = 15.97,
    ) {
    }

    public function getPrice(): ?object
    {
        if (!$this->withPrice) {
            return null;
        }

        return new class ($this->gross, $this->net, $this->vat) {
            public function __construct(
                private readonly float $gross,
                private readonly float $net,
                private readonly float $vat,
            ) {
            }

            public function getBruttoPrice(): float
            {
                return $this->gross;
            }

            public function getNettoPrice(): float
            {
                return $this->net;
            }

            public function getVatValue(): float
            {
                return $this->vat;
            }
        };
    }

    public function getBasketCurrency(): ?object
    {
        if ($this->currency === null) {
            return null;
        }
        $currency = new stdClass();
        $currency->name = $this->currency;

        return $currency;
    }
}
