<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

/**
 * Interface for parsing raw webhook HTTP requests.
 *
 * Extracts payload, signature, and metadata from incoming webhook requests.
 *
 * @since Sprint 13
 */
interface WebhookRequestParserInterface
{
    /**
     * Parse a raw webhook request into a WebhookRequest object.
     *
     * @param string $rawBody The raw HTTP request body
     * @param array<string, string> $headers HTTP headers (key => value)
     * @param string $remoteIp Client IP address
     * @return WebhookRequest The parsed request
     * @throws \InvalidArgumentException If the request cannot be parsed
     */
    public function parse(string $rawBody, array $headers, string $remoteIp): WebhookRequest;
}
