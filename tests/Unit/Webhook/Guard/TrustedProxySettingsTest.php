<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook\Guard;

use OxidEsales\PaymentBase\Webhook\Guard\TrustedProxySettings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * VR-19 W2b — which reverse proxies may name the webhook caller's address, as one shop-wide
 * setting for every provider's webhook (Mollie's rate limit keyed on REMOTE_ADDR behind
 * Cloudflare: forged ids from anywhere could exhaust the budget of Mollie's real deliveries).
 */
final class TrustedProxySettingsTest extends TestCase
{
    public function testTheConfiguredProxiesNameTheClient(): void
    {
        $resolver = $this->reading("173.245.48.0/20, 2400:cb00::/32\n172.18.0.1", 'CF-Connecting-IP')->clientIp();

        self::assertSame('198.51.100.7', $resolver->resolve('172.18.0.1', ['CF-Connecting-IP' => '198.51.100.7']));
        self::assertSame('198.51.100.7', $resolver->resolve('173.245.48.9', ['cf-connecting-ip' => '198.51.100.7']));
        self::assertSame('203.0.113.66', $resolver->resolve('203.0.113.66', ['CF-Connecting-IP' => '198.51.100.7']));
    }

    public function testNoProxiesConfiguredMeansTheRemoteAddressIsTheClient(): void
    {
        $resolver = $this->reading('', 'CF-Connecting-IP')->clientIp();

        self::assertSame('172.18.0.1', $resolver->resolve('172.18.0.1', ['CF-Connecting-IP' => '198.51.100.7']));
    }

    public function testAnEmptyHeaderNameFallsBackToXForwardedFor(): void
    {
        $resolver = $this->reading('172.18.0.1', '  ')->clientIp();

        self::assertSame('198.51.100.7', $resolver->resolve('172.18.0.1', ['X-Forwarded-For' => '198.51.100.7, 172.18.0.1']));
    }

    public function testAnUnreadableSettingStoreTrustsNoProxy(): void
    {
        $settings = new class () extends TrustedProxySettings {
            protected function readRaw(string $name): string
            {
                throw new RuntimeException('no container');
            }
        };

        self::assertSame('172.18.0.1', $settings->clientIp()->resolve('172.18.0.1', ['X-Forwarded-For' => '198.51.100.7']));
    }

    private function reading(string $proxies, string $header): TrustedProxySettings
    {
        return new class ($proxies, $header) extends TrustedProxySettings {
            public function __construct(private readonly string $proxies, private readonly string $header)
            {
            }

            protected function readRaw(string $name): string
            {
                return $name === self::PROXIES_SETTING ? $this->proxies : $this->header;
            }
        };
    }
}
