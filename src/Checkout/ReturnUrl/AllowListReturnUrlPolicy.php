<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\ReturnUrl;

use OxidEsales\PaymentBase\Validation\Guard\ShopUrlResolverInterface;

/**
 * Allowed: an absolute http(s) URL without credentials whose origin
 * (scheme, host, port) is the shop's own or one the merchant listed.
 * Everything is compared as an origin - the path and query are the client's
 * business - with default ports normalised away, so `https://shop:443/` and
 * `https://shop/` are one origin and `http://shop/` is not.
 *
 * @since 3.0.0
 */
final class AllowListReturnUrlPolicy implements ReturnUrlPolicyInterface
{
    private const SCHEMES = ['http', 'https'];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(
        private readonly ShopUrlResolverInterface $shopUrl,
        private readonly ReturnUrlSettingsInterface $settings,
    ) {
    }

    public function assertAllowed(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new ReturnUrlRejectedException($url, ReturnUrlRejectedException::NOT_ABSOLUTE);
        }

        // Scheme first: `javascript:` has no host either, and "not absolute"
        // would be the wrong story about it.
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, self::SCHEMES, true)) {
            throw new ReturnUrlRejectedException($url, ReturnUrlRejectedException::SCHEME_NOT_ALLOWED);
        }
        if ($scheme === '' || !isset($parts['host']) || $parts['host'] === '') {
            throw new ReturnUrlRejectedException($url, ReturnUrlRejectedException::NOT_ABSOLUTE);
        }

        $origin = $this->origin($scheme, $parts['host'], $parts['port'] ?? null);
        if (!in_array($origin, $this->allowedOrigins(), true)) {
            throw new ReturnUrlRejectedException($url, ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ReturnUrlRejectedException($url, ReturnUrlRejectedException::CREDENTIALS_NOT_ALLOWED);
        }

        return $url;
    }

    public function isAllowed(string $url): bool
    {
        try {
            $this->assertAllowed($url);

            return true;
        } catch (ReturnUrlRejectedException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $origins = [];
        $shop = $this->originOf($this->shopUrl->getShopUrl());
        if ($shop !== null) {
            $origins[] = $shop;
        }

        foreach ($this->settings->getAllowedOrigins() as $entry) {
            $origin = $this->originOf($entry);
            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    /**
     * The origin of a merchant-typed entry or the shop URL; an entry without
     * a scheme means https.
     */
    private function originOf(string $entry): ?string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $entry)) {
            $entry = 'https://' . $entry;
        }

        $parts = parse_url($entry);
        if ($parts === false || !isset($parts['host'], $parts['scheme']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, self::SCHEMES, true)) {
            return null;
        }

        return $this->origin($scheme, $parts['host'], $parts['port'] ?? null);
    }

    private function origin(string $scheme, string $host, ?int $port): string
    {
        $host = strtolower($host);
        if ($port === null || $port === self::DEFAULT_PORTS[$scheme]) {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . $host . ':' . $port;
    }
}
