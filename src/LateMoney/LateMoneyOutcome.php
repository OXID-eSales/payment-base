<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

/**
 * What became of money that arrived on a contract that can no longer take it.
 *
 * Every kind is an answer the provider's webhook can give with HTTP 200: the money is back, was never there,
 * or the order is flagged for the merchant. None of them is a reason for the provider to retry for days.
 *
 * @since 1.8.0
 */
final readonly class LateMoneyOutcome
{
    /**
     * @param string $reference `refunded:<refund id>`, `released`, the earlier record, or the failure reason
     */
    private function __construct(public LateMoneyKind $kind, public string $reference)
    {
    }

    public static function refunded(string $providerRefundId): self
    {
        return new self(LateMoneyKind::Refunded, $providerRefundId);
    }

    public static function released(): self
    {
        return new self(LateMoneyKind::Released, '');
    }

    public static function noMoney(): self
    {
        return new self(LateMoneyKind::NoMoney, '');
    }

    public static function alreadySettled(string $record): self
    {
        return new self(LateMoneyKind::AlreadySettled, $record);
    }

    public static function failed(string $reason): self
    {
        return new self(LateMoneyKind::Failed, $reason);
    }
}
