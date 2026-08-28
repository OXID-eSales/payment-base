<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Result;

/**
 * What one cleanup run did.
 *
 * A bare count would not let the command tell "nothing was abandoned" apart
 * from "everything failed", which is the difference an operator reading cron
 * output actually cares about.
 */
final class NotFinishedOrderCleanupResult
{
    public function __construct(
        /** Candidates the query returned. */
        public readonly int $scanned,
        /** Orders this run moved to storno + CANCELLED. */
        public readonly int $ordersCancelled,
        /** Linked contracts this run cancelled alongside their order. */
        public readonly int $contractsCancelled,
        /** Voucher reservations released back to customers. */
        public readonly int $vouchersReleased,
        /** Candidates that threw and were skipped. */
        public readonly int $failed,
        /** True when nothing was written. */
        public readonly bool $dryRun
    ) {
    }
}
