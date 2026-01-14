<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Service for calculating fraud risk scores.
 *
 * Analyzes various data points to determine the likelihood of fraud:
 * - Address matching (billing vs shipping)
 * - Email domain validation (disposable emails)
 * - Order value analysis
 * - IP address patterns
 *
 * Risk Score Ranges:
 * - 0-29: Low risk (approve automatically)
 * - 30-69: Medium risk (manual review recommended)
 * - 70-100: High risk (reject or require additional verification)
 *
 * @since 1.0.0
 */
interface FraudScoringServiceInterface
{
    /**
     * Calculate risk score for a payment transaction.
     *
     * @param array<string, mixed> $data Transaction data including:
     *                                    - amount: float
     *                                    - currency: string
     *                                    - billingAddress: array
     *                                    - shippingAddress: array
     *                                    - email: string
     *                                    - ipAddress: string
     * @return int Risk score between 0-100
     */
    public function calculateRiskScore(array $data): int;

    /**
     * Check if an email address is from a disposable email provider.
     *
     * @param string $email Email address to check
     * @return bool True if disposable, false otherwise
     */
    public function isDisposableEmail(string $email): bool;

    /**
     * Check if two addresses match.
     *
     * @param array<string, string> $address1 First address
     * @param array<string, string> $address2 Second address
     * @return bool True if addresses match, false otherwise
     */
    public function addressesMatch(array $address1, array $address2): bool;
}
