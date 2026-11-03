<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook\Guard;

use OxidEsales\PaymentBase\Tests\Support\Validation\InMemoryRateLimitStore;
use OxidEsales\PaymentBase\Webhook\Guard\HttpsGuard;
use OxidEsales\PaymentBase\Webhook\Guard\IpAllowlistGuard;
use OxidEsales\PaymentBase\Webhook\Guard\PayloadSizeGuard;
use OxidEsales\PaymentBase\Webhook\Guard\RateLimitGuard;
use OxidEsales\PaymentBase\Webhook\Guard\TrustedProxyClientIp;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardChain;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardInterface;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardResult;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookHttpRequest;
use PHPUnit\Framework\TestCase;

/**
 * VR-19 W2 — the one webhook guard chain the providers share. It replaces three
 * diverged copies (stripe, mollie-payment, paypal): PayPal's rate limiter kept
 * its counters in the guard object (reset every request), Mollie keyed on
 * REMOTE_ADDR behind Cloudflare, PayPal let every private address through.
 */
final class WebhookGuardsTest extends TestCase
{
    public function testTheChainStopsAtTheFirstRejection(): void
    {
        $second = $this->createMock(WebhookGuardInterface::class);
        $second->expects(self::never())->method('check');

        $result = (new WebhookGuardChain([new HttpsGuard(), $second]))->check($this->request(scheme: 'http'));

        self::assertFalse($result->ok);
        self::assertSame(400, $result->httpStatus);
        self::assertSame('tls_required', $result->reason);
    }

    public function testAPassingChainPasses(): void
    {
        self::assertTrue((new WebhookGuardChain([new HttpsGuard(), new PayloadSizeGuard()]))->check($this->request())->ok);
    }

    public function testHttpPassesOnlyWhenDevelopmentAllowsIt(): void
    {
        self::assertTrue((new HttpsGuard(allowHttpForDevelopment: true))->check($this->request(scheme: 'http'))->ok);
    }

    public function testAPayloadAboveTheLimitIsRejectedEvenWhenContentLengthLies(): void
    {
        $result = (new PayloadSizeGuard(maxBytes: 10))->check($this->request(contentLength: 5, body: str_repeat('x', 11)));

        self::assertSame(413, $result->httpStatus);
        self::assertSame('payload_too_large', $result->reason);
    }

    public function testTheRateLimitCountsInTheSharedStoreAcrossGuardInstances(): void
    {
        $store = new InMemoryRateLimitStore();
        $clock = static fn (): int => 1_000_000;

        for ($i = 0; $i < 3; $i++) {
            // A new guard per request, as PHP builds one per request - the count must survive.
            self::assertTrue((new RateLimitGuard($store, 'paypal', 3, 60, $clock))->check($this->request())->ok);
        }
        $fourth = (new RateLimitGuard($store, 'paypal', 3, 60, $clock))->check($this->request());

        self::assertSame(429, $fourth->httpStatus);
        self::assertSame('rate_limited', $fourth->reason);
    }

    public function testTheRateLimitOfOneProviderAndClientDoesNotTouchAnother(): void
    {
        $store = new InMemoryRateLimitStore();
        $clock = static fn (): int => 1_000_000;
        $flooding = new RateLimitGuard($store, 'mollie', 1, 60, $clock);
        $flooding->check($this->request(clientIp: '198.51.100.7'));
        $flooding->check($this->request(clientIp: '198.51.100.7'));

        self::assertTrue($flooding->check($this->request(clientIp: '203.0.113.9'))->ok, 'another client');
        self::assertTrue((new RateLimitGuard($store, 'stripe', 1, 60, $clock))->check($this->request(clientIp: '198.51.100.7'))->ok, 'another provider');
    }

    public function testAnEmptyAllowListAllowsEveryone(): void
    {
        self::assertTrue((new IpAllowlistGuard([]))->check($this->request(clientIp: '10.0.0.1'))->ok);
    }

    public function testTheAllowListMatchesIpv4AndIpv6Ranges(): void
    {
        $guard = new IpAllowlistGuard(['203.0.113.0/24', '2001:db8::/32', '198.51.100.7']);

        self::assertTrue($guard->check($this->request(clientIp: '203.0.113.200'))->ok);
        self::assertTrue($guard->check($this->request(clientIp: '2001:db8:1::5'))->ok);
        self::assertTrue($guard->check($this->request(clientIp: '198.51.100.7'))->ok);
        self::assertSame(403, $guard->check($this->request(clientIp: '203.0.114.1'))->httpStatus);
        self::assertSame(403, $guard->check($this->request(clientIp: '2001:db9::1'))->httpStatus);
    }

    public function testPrivateAddressesAreNotAllowedImplicitly(): void
    {
        // PayPal's copy let every private / loopback address through: behind any proxy, everyone passed.
        self::assertSame(403, (new IpAllowlistGuard(['203.0.113.0/24']))->check($this->request(clientIp: '10.0.0.1'))->httpStatus);
    }

    public function testAMissingClientIpIsRejectedWhenAnAllowListIsSet(): void
    {
        self::assertSame('missing_client_ip', (new IpAllowlistGuard(['203.0.113.0/24']))->check($this->request(clientIp: ''))->reason);
    }

    public function testTheProxyHeaderIsTrustedOnlyFromATrustedProxy(): void
    {
        $resolver = new TrustedProxyClientIp(['172.18.0.0/16'], 'CF-Connecting-IP');

        self::assertSame('198.51.100.7', $resolver->resolve('172.18.0.1', ['CF-Connecting-IP' => '198.51.100.7']));
        self::assertSame('203.0.113.66', $resolver->resolve('203.0.113.66', ['CF-Connecting-IP' => '198.51.100.7']), 'a client cannot claim another address');
        self::assertSame('172.18.0.1', $resolver->resolve('172.18.0.1', []), 'no header: the proxy itself');
        self::assertSame('172.18.0.1', $resolver->resolve('172.18.0.1', ['CF-Connecting-IP' => 'not-an-ip']), 'a malformed header is ignored');
    }

    public function testWithoutTrustedProxiesTheRemoteAddressIsTheClient(): void
    {
        self::assertSame('172.18.0.1', (new TrustedProxyClientIp())->resolve('172.18.0.1', ['CF-Connecting-IP' => '198.51.100.7']));
    }

    public function testTheHeaderNameIsCaseInsensitive(): void
    {
        $resolver = new TrustedProxyClientIp(['172.18.0.1'], 'X-Forwarded-For');

        self::assertSame('198.51.100.7', $resolver->resolve('172.18.0.1', ['x-forwarded-for' => '198.51.100.7, 172.18.0.1']));
    }

    public function testAGuardResultCarriesItsStatusAndReason(): void
    {
        self::assertTrue(WebhookGuardResult::pass()->ok);
        self::assertSame([false, 429, 'rate_limited'], [
            WebhookGuardResult::reject(429, 'rate_limited')->ok,
            WebhookGuardResult::reject(429, 'rate_limited')->httpStatus,
            WebhookGuardResult::reject(429, 'rate_limited')->reason,
        ]);
    }

    private function request(
        string $scheme = 'https',
        string $clientIp = '203.0.113.10',
        int $contentLength = 2,
        string $body = '{}'
    ): WebhookHttpRequest {
        return new WebhookHttpRequest($scheme, $clientIp, $contentLength, $body);
    }
}
