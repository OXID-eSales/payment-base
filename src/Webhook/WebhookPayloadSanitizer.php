<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

/**
 * Strips PII from webhook payloads before persistence.
 *
 * Sprint 69a (H7): GDPR Article 5(1)(c) — data minimization.
 * Original payload stays in memory for processing; only
 * the redacted version is stored in webhook logs.
 *
 * @since 2.1.0
 */
final class WebhookPayloadSanitizer
{
    /**
     * Top-level keys that contain PII — replaced entirely with [REDACTED].
     */
    private const PII_KEYS = [
        'customer_details',
        'customer_email',
        'customer_name',
        'shipping',
        'billing_details',
        'receipt_email',
        'metadata',
    ];

    /**
     * Nested keys that contain PII — stripped at any depth.
     */
    private const PII_NESTED_KEYS = [
        'email',
        'name',
        'phone',
        'address',
        'last4',
        'exp_month',
        'exp_year',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->stripRecursive($payload);
        return $result;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function stripRecursive(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::PII_KEYS, true)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            if (is_string($key) && in_array($key, self::PII_NESTED_KEYS, true)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->stripRecursive($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
