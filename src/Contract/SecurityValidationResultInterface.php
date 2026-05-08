<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

/**
 * Interface for security validation results.
 *
 * Used to assess fraud risk when users return from external payment providers.
 * Any payment provider can implement this interface with provider-specific
 * validation logic while maintaining consistent behavior (LSP compliance).
 */
interface SecurityValidationResultInterface
{
    /**
     * Get the security score (0-100).
     * 100 = fully trusted, 0 = highly suspicious
     */
    public function getScore(): int;

    /**
     * Get list of warning codes.
     *
     * @return array<int, string>
     */
    public function getWarnings(): array;

    /**
     * Check if a specific warning exists.
     */
    public function hasWarning(string $warning): bool;

    /**
     * Get the number of warnings.
     */
    public function getWarningCount(): int;

    /**
     * Whether the return should be allowed based on security checks.
     */
    public function isAllowed(): bool;

    /**
     * Convert to array for logging/serialization.
     *
     * @return array{score: int, warnings: array<int, string>, allowed: bool}
     */
    public function toArray(): array;
}
