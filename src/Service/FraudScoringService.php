<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Implementation of fraud scoring service.
 *
 * Uses multiple heuristics to calculate risk:
 * 1. Address mismatch: +20 points
 * 2. Disposable email: +50 points
 * 3. High order value (€500+): +15 points
 * 4. Very high value (€1000+): +25 points
 * 5. Base risk: 10 points (default)
 *
 * @since 1.0.0
 */
class FraudScoringService implements FraudScoringServiceInterface
{
    private const BASE_RISK_SCORE = 10;
    private const POINTS_ADDRESS_MISMATCH = 20;
    private const POINTS_DISPOSABLE_EMAIL = 50;
    private const POINTS_HIGH_VALUE = 15;
    private const POINTS_VERY_HIGH_VALUE = 25;
    private const THRESHOLD_HIGH_VALUE = 500.00;
    private const THRESHOLD_VERY_HIGH_VALUE = 1000.00;

    /**
     * Known disposable email domains
     */
    private const DISPOSABLE_DOMAINS = [
        'tempmail.com',
        'guerrillamail.com',
        '10minutemail.com',
        'mailinator.com',
        'throwaway.email',
        'temp-mail.org',
        'yopmail.com',
    ];

    public function calculateRiskScore(array $data): int
    {
        $score = self::BASE_RISK_SCORE;

        // Check address mismatch
        if (
            isset($data['billingAddress'], $data['shippingAddress'])
            && !$this->addressesMatch($data['billingAddress'], $data['shippingAddress'])
        ) {
            $score += self::POINTS_ADDRESS_MISMATCH;
        }

        // Check disposable email
        if (isset($data['email']) && $this->isDisposableEmail($data['email'])) {
            $score += self::POINTS_DISPOSABLE_EMAIL;
        }

        // Check order value
        if (isset($data['amount'])) {
            $amount = (float) $data['amount'];

            if ($amount >= self::THRESHOLD_VERY_HIGH_VALUE) {
                $score += self::POINTS_VERY_HIGH_VALUE;
            } elseif ($amount >= self::THRESHOLD_HIGH_VALUE) {
                $score += self::POINTS_HIGH_VALUE;
            }
        }

        // Ensure score stays within 0-100 range
        return min(100, max(0, $score));
    }

    public function isDisposableEmail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        foreach (self::DISPOSABLE_DOMAINS as $disposableDomain) {
            if ($domain === $disposableDomain) {
                return true;
            }
        }

        return false;
    }

    public function addressesMatch(array $address1, array $address2): bool
    {
        $fields = ['street', 'city', 'zip', 'country'];

        foreach ($fields as $field) {
            $value1 = isset($address1[$field]) ? trim((string) $address1[$field]) : '';
            $value2 = isset($address2[$field]) ? trim((string) $address2[$field]) : '';

            if (strcasecmp($value1, $value2) !== 0) {
                return false;
            }
        }

        return true;
    }
}
