<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Factory;

use Closure;
use OxidEsales\PaymentBase\Service\FileLogger;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Service\NullFileLogger;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

/**
 * Abstract factory for creating file loggers.
 *
 * Sprint 27: Template Method Pattern - subclasses define log file path and prefix.
 * Phase 1: Optional closure gating seam — ?\Closure $isEnabled = null.
 *   - null (default): always returns FileLogger — identical to prior behavior (LSP/back-compat).
 *   - closure returning false: returns NullFileLogger (channel disabled).
 *   - closure returning true: returns FileLogger.
 *   Closure is evaluated lazily inside create(), not in the constructor.
 *
 * SOLID Principles:
 * - OCP: Open for extension via abstract methods and the optional closure seam
 * - DIP: Depends on FileLoggerInterface abstraction
 * - LSP: NullFileLogger is a drop-in for FileLogger at the FileLoggerInterface type
 * - Template Method: Algorithm skeleton in base class
 *
 * Note: Subclasses must implement getShopDirectory() to provide the shop path.
 * This allows platform-specific implementations (OXID, Shopware, etc.).
 *
 * @since 2.0.0
 */
abstract class AbstractFileLoggerFactory
{
    /**
     * Optional gating closure. Evaluated lazily inside create().
     * null means always-enabled (back-compat default).
     */
    private ?Closure $isEnabled = null;

    /**
     * @param ?Closure $isEnabled Optional gate: called inside create(); returns bool.
     *                            null (default) preserves existing always-on behavior.
     */
    public function __construct(?Closure $isEnabled = null)
    {
        $this->isEnabled = $isEnabled;
    }

    /**
     * Get the log file path relative to shop directory.
     *
     * @return string Relative path (e.g., 'log/osc/stripe_events.log')
     */
    abstract protected function getLogFile(): string;

    /**
     * Get the log entry prefix.
     *
     * @return string Prefix for log entries (e.g., 'EVENT')
     */
    abstract protected function getPrefix(): string;

    /**
     * Get the shop directory path.
     *
     * Platform-specific implementations must provide this.
     *
     * @return string Absolute path to shop directory
     */
    abstract protected function getShopDirectory(): string;

    /**
     * Create the file logger.
     *
     * Template method: uses abstract methods to get file path and prefix.
     * When the gating closure is set and returns false, a NullFileLogger is
     * returned so no file is written. The null-closure path (default) behaves
     * identically to the pre-Phase-1 implementation.
     *
     * @return FileLoggerInterface
     * @throws RuntimeException If shop directory not configured
     */
    public function create(): FileLoggerInterface
    {
        if ($this->isEnabled !== null && ($this->isEnabled)() === false) {
            return new NullFileLogger();
        }

        $shopDir = $this->getShopDirectory();

        if (empty($shopDir)) {
            throw new RuntimeException('Shop directory not configured');
        }

        $logFilePath = Path::join(rtrim($shopDir, '/'), $this->getLogFile());

        return new FileLogger($logFilePath, $this->getPrefix());
    }
}
