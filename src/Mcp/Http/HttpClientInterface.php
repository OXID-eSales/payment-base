<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Http;

/**
 * The one outbound GET the MCP/ACP surface needs (the issuer's JWKS document).
 * Injected, so tests never touch the network.
 */
interface HttpClientInterface
{
    /**
     * @return string The response body of a 200 answer
     *
     * @throws HttpRequestFailedException on a transport error or any other status
     */
    public function get(string $url): string;
}
