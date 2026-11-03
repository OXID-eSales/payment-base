<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * Empty list: everyone (the PSP's signature is the authentication). Otherwise
 * only the listed addresses / CIDR ranges, IPv4 and IPv6. Nothing is allowed
 * implicitly - private and loopback addresses included.
 */
final class IpAllowlistGuard implements WebhookGuardInterface
{
    /**
     * @param list<string> $allowedCidrs
     */
    public function __construct(private readonly array $allowedCidrs = [])
    {
    }

    public function check(WebhookHttpRequest $request): WebhookGuardResult
    {
        if ($this->allowedCidrs === []) {
            return WebhookGuardResult::pass();
        }
        $ip = trim($request->clientIp);
        if ($ip === '') {
            return WebhookGuardResult::reject(400, 'missing_client_ip');
        }
        foreach ($this->allowedCidrs as $cidr) {
            if (IpRange::contains($cidr, $ip)) {
                return WebhookGuardResult::pass();
            }
        }

        return WebhookGuardResult::reject(403, 'ip_not_allowed');
    }
}
