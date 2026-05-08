<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Http;

interface HttpClientInterface
{
    /**
     * Send an HTTP POST request.
     *
     * @param string $url Target URL
     * @param string $body Request body
     * @param array<string, string> $headers Request headers
     * @param int $timeoutSeconds Request timeout
     */
    public function post(
        string $url,
        string $body,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse;

    /**
     * Send an HTTP GET request.
     *
     * @param string $url Target URL
     * @param array<string, string> $headers Request headers
     * @param int $timeoutSeconds Request timeout
     */
    public function get(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse;
}
