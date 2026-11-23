<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Attempt;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;

/**
 * MOL-39: one unique row in oe_payments_idempotency per contract and row version.
 *
 * The row version is part of the key on purpose. Requests that race for one order loaded the same
 * version of the contract, so they meet on one row. A contract that is reopened for a new attempt
 * (OPC-175) is a draft again and passes the order step a second time: it is a later version, so the
 * claim of the first attempt does not refuse it.
 *
 * @since sprint 143 (AW)
 */
class OrderCreationClaim implements OrderCreationClaimInterface
{
    private const OPERATION = 'early-order-create';

    private const STATUS_CLAIMED = 'claimed';

    private const STATUS_RELEASED = 'released';

    /**
     * How long a row outlives a request that died between the claim and its end. The contract is no
     * draft after a created order, so the row only has to cover the requests that are in flight.
     */
    private const LEASE = '+5 minutes';

    public function __construct(private readonly IdempotencyRepositoryInterface $records)
    {
    }

    public function claim(PaymentContractInterface $contract): bool
    {
        $now = new DateTimeImmutable();

        return $this->records->claim($this->record($contract, self::STATUS_CLAIMED, $now, $now->modify(self::LEASE)), $now);
    }

    public function release(PaymentContractInterface $contract): void
    {
        $now = new DateTimeImmutable();

        $this->records->save($this->record($contract, self::STATUS_RELEASED, $now, $now->modify('-1 second')));
    }

    private function record(
        PaymentContractInterface $contract,
        string $status,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt
    ): IdempotencyRecord {
        $key = $this->keyOf($contract);

        return new IdempotencyRecord(md5($key), $key, '', self::OPERATION, $status, $now, $expiresAt);
    }

    private function keyOf(PaymentContractInterface $contract): string
    {
        $version = $contract->toArray()['version'] ?? 0;

        return 'order-creation:' . $contract->getId() . ':v' . (is_int($version) ? $version : 0);
    }
}
