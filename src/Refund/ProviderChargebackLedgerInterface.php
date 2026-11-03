<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

use InvalidArgumentException;
use RuntimeException;

/**
 * VR-21 F3 (S-M6) - chargebacks of a payment are audited by what changed. A provider reports the chargeback
 * TOTAL of a payment (Mollie: `amountChargedBack`), and reports it again with every status webhook; booking the
 * total each time counted the same chargeback again. The ledger books the difference to the total booked so far:
 * 30 then 50 are +30 and +20, a reversal back to 0 is -50, a replay is nothing. Audit only - no contract state
 * changes; what a chargeback means for the order is the operator's decision.
 *
 * @since 1.7.0
 */
interface ProviderChargebackLedgerInterface
{
    /**
     * @param string|null $providerChargebackId the provider's id of the chargeback, kept on the audit row when it
     *                                          has one (a reversal row carries it with the suffix ":reversal")
     * @param float $cumulativeAmount           everything charged back on the payment as of now; 0 once reversed
     * @param string $source                    who reports it: 'webhook', 'admin' (for the log)
     *
     * @throws InvalidArgumentException negative total, currency other than the contract's
     * @throws RuntimeException no such contract
     */
    public function book(
        string $contractId,
        ?string $providerChargebackId,
        float $cumulativeAmount,
        string $currency,
        string $source
    ): ChargebackBooking;
}
