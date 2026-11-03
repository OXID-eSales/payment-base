<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

/**
 * Strips personal data and secrets from a webhook payload before it is stored or logged
 * (GDPR art. 5(1)(c)). The original stays in memory for processing.
 *
 * VR-18 L2: payment-base knows the generic vocabulary only - a key that contains one of
 * GENERIC_KEY_FRAGMENTS is redacted with everything below it. What is PII in one PSP's payload under
 * a name that says nothing (`payer`, `consumerAccount`, `metadata`) is that provider's knowledge,
 * contributed through its tagged WebhookPiiKeyProvider.
 */
final class WebhookPayloadSanitizer
{
    private const REDACTED = '[REDACTED]';
    private const TRUNCATED = '[TRUNCATED]';

    /** A payload nested deeper than this is not an event, it is an attack on the log. */
    private const MAX_DEPTH = 10;

    private const GENERIC_KEY_FRAGMENTS = [
        'secret',
        'token',
        'password',
        'email',
        'iban',
        'address',
        'phone',
        'name',
    ];

    /** @var list<string> lower-cased */
    private readonly array $providerKeys;

    /**
     * @param iterable<WebhookPiiKeyProvider> $keyProviders
     */
    public function __construct(iterable $keyProviders)
    {
        $keys = [];
        foreach ($keyProviders as $keyProvider) {
            foreach ($keyProvider->piiKeys() as $key) {
                $keys[] = strtolower($key);
            }
        }
        $this->providerKeys = $keys;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        /** @var array<string, mixed> $sanitized the keys of $payload are kept as they are */
        $sanitized = $this->sanitizeLevel($payload, 0);

        return $sanitized;
    }

    /**
     * @param array<array-key, mixed> $level
     * @return array<array-key, mixed>
     */
    private function sanitizeLevel(array $level, int $depth): array
    {
        $result = [];
        foreach ($level as $key => $value) {
            $result[$key] = $this->sanitizeValue($key, $value, $depth);
        }

        return $result;
    }

    private function sanitizeValue(int|string $key, mixed $value, int $depth): mixed
    {
        if (is_string($key) && $this->isPiiKey($key)) {
            return self::REDACTED;
        }
        if (!is_array($value)) {
            return $value;
        }

        return $depth >= self::MAX_DEPTH ? self::TRUNCATED : $this->sanitizeLevel($value, $depth + 1);
    }

    private function isPiiKey(string $key): bool
    {
        $lowered = strtolower($key);
        if (in_array($lowered, $this->providerKeys, true)) {
            return true;
        }
        foreach (self::GENERIC_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lowered, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
