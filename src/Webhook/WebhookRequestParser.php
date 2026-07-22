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
 * The signature header name is provider-specific (Stripe: `Stripe-Signature`,
 * PayPal: `PayPal-Transmission-Sig`, …) and is injected at construction. This
 * agnostic parser holds no knowledge of any single provider's header.
 *
 * @since Sprint 13
 */
class WebhookRequestParser implements WebhookRequestParserInterface
{
    public function __construct(
        private readonly string $signatureHeader
    ) {
    }

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
        if (isset($headers[$this->signatureHeader])) {
            return $headers[$this->signatureHeader];
        }

        // Try lowercase
        $lowercaseKey = strtolower($this->signatureHeader);
        if (isset($headers[$lowercaseKey])) {
            return $headers[$lowercaseKey];
        }

        // Try HTTP-prefixed (from $_SERVER)
        $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $this->signatureHeader));
        if (isset($headers[$httpKey])) {
            return $headers[$httpKey];
        }

        // Search case-insensitively
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $this->signatureHeader) === 0) {
                return $value;
            }
            if (strcasecmp($key, $httpKey) === 0) {
                return $value;
            }
        }

        return '';
    }
}
