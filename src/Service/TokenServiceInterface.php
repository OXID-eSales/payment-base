<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Interface for contract token services.
 *
 * Generates and validates secure tokens for contract identification in URLs.
 * Used to securely pass contract IDs in return URLs from payment providers
 * without exposing the raw contract ID or allowing tampering.
 *
 * Any payment provider can implement this interface with provider-specific
 * token generation while maintaining consistent behavior (LSP compliance).
 */
interface TokenServiceInterface
{
    /**
     * Generate a secure, URL-safe token for a contract ID.
     */
    public function generateToken(string $contractId): string;

    /**
     * Validate that a token is valid for the given contract ID.
     */
    public function validateToken(string $token, string $contractId): bool;

    /**
     * Extract the contract ID from a token.
     *
     * @return string|null The contract ID, or null if token is invalid
     */
    public function extractContractId(string $token): ?string;
}
