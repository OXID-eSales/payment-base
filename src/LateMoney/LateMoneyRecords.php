<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The audit of late money on a contract, kept in its metadata (`late_money`) keyed by the provider payment
 * id: what was done and when. One record per payment is what makes a second delivery a no-op.
 *
 * @phpstan-type LateMoneyRecord array{outcome: string, at: string}
 *
 * @since 1.8.0
 */
final class LateMoneyRecords
{
    public const METADATA_KEY = 'late_money';

    /**
     * @return array<string, LateMoneyRecord>
     */
    public static function of(PaymentContractInterface $contract): array
    {
        $stored = $contract->getMetadata(self::METADATA_KEY);

        /** @var array<string, LateMoneyRecord> $records */
        $records = is_array($stored) ? $stored : [];

        return $records;
    }

    public static function outcomeFor(PaymentContractInterface $contract, string $providerPaymentId): ?string
    {
        return self::of($contract)[$providerPaymentId]['outcome'] ?? null;
    }

    /**
     * @param string $outcome `refunded:<id>` or `released`
     */
    public static function record(PaymentContractInterface $contract, string $providerPaymentId, string $outcome): void
    {
        $records = self::of($contract);
        $records[$providerPaymentId] = ['outcome' => $outcome, 'at' => (new DateTimeImmutable())->format(DATE_ATOM)];
        $contract->setMetadata(self::METADATA_KEY, $records);
    }
}
