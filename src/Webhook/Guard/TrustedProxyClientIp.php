<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * The caller's address. Behind a reverse proxy (Cloudflare, the docker gateway on
 * pay1) REMOTE_ADDR is the proxy, so every PSP delivery and every attacker share
 * one rate-limit bucket. The proxy's header is believed only when the request
 * comes from a configured proxy; anyone else cannot claim another address.
 * No trusted proxies configured: REMOTE_ADDR, as before.
 */
final class TrustedProxyClientIp
{
    /**
     * @param list<string> $trustedProxies CIDR ranges / addresses of the reverse proxies
     * @param string $header e.g. CF-Connecting-IP or X-Forwarded-For (first entry is used)
     */
    public function __construct(
        private readonly array $trustedProxies = [],
        private readonly string $header = 'X-Forwarded-For',
    ) {
    }

    /**
     * @param array<string, string> $headers request headers, any case
     */
    public function resolve(string $remoteAddr, array $headers): string
    {
        if (!$this->fromTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }
        $value = $this->headerValue($headers);
        $first = trim(explode(',', $value)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $remoteAddr;
    }

    private function fromTrustedProxy(string $remoteAddr): bool
    {
        foreach ($this->trustedProxies as $proxy) {
            if (IpRange::contains($proxy, $remoteAddr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private function headerValue(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, $this->header) === 0) {
                return $value;
            }
        }

        return '';
    }
}
