<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Logging;

use Throwable;

/**
 * What a log line may say about a Throwable: never its message.
 */
interface ThrowableSummaryInterface
{
    /**
     * @return array<string, scalar|null> at least `class` and `code`; `sqlState` and provider fields when known
     */
    public function fromThrowable(Throwable $throwable): array;

    /**
     * One-line form for places that keep a single string (a webhook log row's error column).
     */
    public function toLine(Throwable $throwable): string;
}
