<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Logging;

use Throwable;

/**
 * A provider's knowledge about the exceptions of its SDK: which fields of one are safe to log
 * (an HTTP status, a request id, the offending field name) - never its message, which may embed
 * the request body.
 *
 * Providers tag their implementation `payment_base.throwable_summary`.
 */
interface ThrowableSummaryExtractorInterface
{
    public function supports(Throwable $throwable): bool;

    /**
     * @return array<string, scalar|null>
     */
    public function summarize(Throwable $throwable): array;
}
