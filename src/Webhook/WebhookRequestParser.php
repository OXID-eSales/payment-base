<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

/**
 * Parser for raw webhook HTTP requests.
 *
 * Extracts payload, signature, and metadata from incoming webhook requests.
 * Handles various header formats (normalized, HTTP-prefixed, case variations).
 *
 * @since Sprint 13
 */
class WebhookRequestParser implements WebhookRequestParserInterface
{
    private const SIGNATURE_HEADER = 'Stripe-Signature';

    /**
     * @inheritDoc
     */
    public function parse(string $rawBody, array $headers, string $remoteIp): WebhookRequest
    {
        if ($rawBody === '') {
            throw new \InvalidArgumentException('Empty payload');
        }

        return new WebhookRequest(
            payload: $rawBody,
            signature: $this->extractSignature($headers),
            remoteIp: $remoteIp,
            receivedAt: new \DateTimeImmutable()
        );
    }

    /**
     * Extract signature from headers, handling various formats.
     *
     * @param array<string, string> $headers
     */
    private function extractSignature(array $headers): string
    {
        // Try exact match first
        if (isset($headers[self::SIGNATURE_HEADER])) {
            return $headers[self::SIGNATURE_HEADER];
        }

        // Try lowercase
        $lowercaseKey = strtolower(self::SIGNATURE_HEADER);
        if (isset($headers[$lowercaseKey])) {
            return $headers[$lowercaseKey];
        }

        // Try HTTP-prefixed (from $_SERVER)
        $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', self::SIGNATURE_HEADER));
        if (isset($headers[$httpKey])) {
            return $headers[$httpKey];
        }

        // Search case-insensitively
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, self::SIGNATURE_HEADER) === 0) {
                return $value;
            }
            if (strcasecmp($key, $httpKey) === 0) {
                return $value;
            }
        }

        return '';
    }
}
