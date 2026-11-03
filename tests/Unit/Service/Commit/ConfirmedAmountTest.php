<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service\Commit;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\Commit\ConfirmedAmount;
use PHPUnit\Framework\TestCase;

/**
 * VR-20 R4 (S2) — the one rule both commit legs apply: what the provider
 * confirms must be what the contract charges.
 */
final class ConfirmedAmountTest extends TestCase
{
    public function testTheSameAmountAndCurrencyMatch(): void
    {
        self::assertTrue(ConfirmedAmount::matches($this->contract(42.0, 'EUR'), 42.0, 'eur'));
    }

    public function testHalfACentOfFloatNoiseStillMatches(): void
    {
        self::assertTrue(ConfirmedAmount::matches($this->contract(0.1 + 0.2, 'EUR'), 0.3, 'EUR'));
    }

    public function testOneCentDoesNotMatch(): void
    {
        self::assertFalse(ConfirmedAmount::matches($this->contract(42.0, 'EUR'), 41.99, 'EUR'));
    }

    public function testAnotherCurrencyDoesNotMatch(): void
    {
        self::assertFalse(ConfirmedAmount::matches($this->contract(42.0, 'EUR'), 42.0, 'CHF'));
    }

    private function contract(float $amount, string $currency): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCurrency')->willReturn($currency);

        return $contract;
    }
}
