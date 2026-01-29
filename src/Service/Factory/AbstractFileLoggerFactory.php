<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Factory;

use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Abstract factory for creating file loggers.
 *
 * Sprint 27: Template Method Pattern - subclasses define log file path and prefix.
 *
 * SOLID Principles:
 * - OCP: Open for extension via abstract methods
 * - DIP: Depends on FileLoggerInterface abstraction
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
     *
     * @return FileLoggerInterface
     * @throws \RuntimeException If shop directory not configured
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = $this->getShopDirectory();

        if (empty($shopDir)) {
            throw new \RuntimeException('Shop directory not configured');
        }

        $logFilePath = Path::join(rtrim($shopDir, '/'), $this->getLogFile());

        return new FileLogger($logFilePath, $this->getPrefix());
    }
}
