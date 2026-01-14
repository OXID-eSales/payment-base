<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Interface for file-based logging.
 *
 * Used for audit logging that must be written to specific files
 * (e.g., reconciliation logs, webhook logs).
 */
interface FileLoggerInterface
{
    /**
     * Log a message to file.
     *
     * @param string $message The log message
     * @param array<string, mixed> $context Additional context data (will be JSON encoded)
     */
    public function log(string $message, array $context = []): void;
}
