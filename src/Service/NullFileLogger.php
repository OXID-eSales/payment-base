<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Null Object implementation of FileLoggerInterface.
 *
 * Used when logging is disabled (e.g., production mode for MCP logs).
 * All methods are no-ops — safe to inject anywhere without side effects.
 *
 * @since Sprint 56
 */
class NullFileLogger implements FileLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(string $message, array $context = []): void
    {
        // No-op: logging disabled
    }
}
