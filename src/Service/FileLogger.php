<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * File-based logger for audit logging.
 *
 * Writes log entries to a specified file with timestamps.
 * Used for reconciliation logs, webhook logs, etc.
 */
class FileLogger implements FileLoggerInterface
{
    public function __construct(
        private readonly string $logFilePath,
        private readonly string $prefix = ''
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $message, array $context = []): void
    {
        $this->ensureDirectoryExists();

        $timestamp = date('Y-m-d H:i:s');
        $prefixPart = $this->prefix !== '' ? $this->prefix . ' ' : '';
        $contextStr = $this->formatContext($context);
        $entry = "[{$timestamp}] {$prefixPart}{$message}{$contextStr}\n";

        file_put_contents($this->logFilePath, $entry, FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectoryExists(): void
    {
        $logDir = dirname($this->logFilePath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }
        return ' ' . json_encode($context);
    }
}
