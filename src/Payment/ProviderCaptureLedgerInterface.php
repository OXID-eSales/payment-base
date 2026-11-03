<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use InvalidArgumentException;
use RuntimeException;

/**
 * Every capture a provider made is booked on its contract exactly once, keyed by the provider's capture id: the
 * counterpart of the refund ledger. The contract's captured total is the sum of its booked captures; one more than
 * the authorisation holds breaks the contract's invariant and is refused.
 *
 * @since 1.7.0
 */
interface ProviderCaptureLedgerInterface
{
    /**
     * @param string $source who reports it: 'admin', 'webhook' (for the log)
     *
     * @throws InvalidArgumentException empty capture id, non-positive amount, currency other than the contract's
     * @throws RuntimeException no such contract
     */
    public function book(string $contractId, string $providerCaptureId, float $amount, string $currency, string $source): CaptureBooking;
}
