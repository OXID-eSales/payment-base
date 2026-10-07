<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\ReturnUrl;

use OxidEsales\PaymentBase\Checkout\ReturnUrl\AllowListReturnUrlPolicy;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlPolicyInterface;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlRejectedException;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlSettingsInterface;
use OxidEsales\PaymentBase\Validation\Guard\ShopUrlResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixedShopUrl implements ShopUrlResolverInterface
{
    public function __construct(private readonly string $url = 'https://shop.example.com/')
    {
    }

    public function getShopUrl(): string
    {
        return $this->url;
    }
}

final class FixedOrigins implements ReturnUrlSettingsInterface
{
    /** @param list<string> $origins */
    public function __construct(private readonly array $origins = [])
    {
    }

    public function getAllowedOrigins(): array
    {
        return $this->origins;
    }
}

/**
 * Sprint 15 / S5 — a headless client hands us the URL the PSP should send
 * the shopper back to. That is an open-redirect surface: a crafted
 * `returnUrl` would bounce a shopper who just paid to a phishing page that
 * knows their contract id. Only the shop itself and the storefront origins
 * the merchant listed may be return targets. Solved once, here, for every
 * provider.
 */
final class AllowListReturnUrlPolicyTest extends TestCase
{
    public function testImplementsThePolicyContract(): void
    {
        self::assertInstanceOf(ReturnUrlPolicyInterface::class, $this->policy());
    }

    public function testTheShopItselfIsAlwaysAllowedWhateverThePathAndQuery(): void
    {
        $url = 'https://shop.example.com/index.php?cl=order&fnc=checkoutSuccess&contract_id=c1';

        self::assertSame($url, $this->policy()->assertAllowed($url));
        self::assertTrue($this->policy()->isAllowed('HTTPS://SHOP.EXAMPLE.COM/thank-you'));
    }

    public function testAConfiguredStorefrontOriginIsAllowed(): void
    {
        $policy = $this->policy(['https://app.example.com', 'https://m.example.com:8443/some/path']);

        self::assertTrue($policy->isAllowed('https://app.example.com/checkout/return?contract_id=c1'));
        self::assertTrue($policy->isAllowed('https://m.example.com:8443/return'));
    }

    public function testAnEntryWithoutASchemeMeansHttps(): void
    {
        $policy = $this->policy(['app.example.com']);

        self::assertTrue($policy->isAllowed('https://app.example.com/return'));
        self::assertFalse($policy->isAllowed('http://app.example.com/return'));
    }

    public function testWithNoEntriesOnlyTheShopIsAllowed(): void
    {
        self::assertFalse($this->policy()->isAllowed('https://app.example.com/return'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'foreign origin' => ['https://evil.example.net/return', ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED];
        yield 'lookalike host' => ['https://shop.example.com.evil.net/', ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED];
        yield 'other scheme on the shop host' => ['http://shop.example.com/return', ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED];
        yield 'other port on the shop host' => ['https://shop.example.com:8080/return', ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED];
        yield 'relative path' => ['/thank-you', ReturnUrlRejectedException::NOT_ABSOLUTE];
        yield 'scheme-relative' => ['//shop.example.com/thank-you', ReturnUrlRejectedException::NOT_ABSOLUTE];
        yield 'empty' => ['', ReturnUrlRejectedException::NOT_ABSOLUTE];
        yield 'javascript' => ['javascript:alert(1)', ReturnUrlRejectedException::SCHEME_NOT_ALLOWED];
        yield 'data' => ['data:text/html,hi', ReturnUrlRejectedException::SCHEME_NOT_ALLOWED];
        yield 'credentials in the authority' => ['https://shop.example.com@evil.example.net/', ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED];
        yield 'credentials on the shop host' => ['https://user:pw@shop.example.com/', ReturnUrlRejectedException::CREDENTIALS_NOT_ALLOWED];
        yield 'backslash trick' => ['https://shop.example.com\\@evil.example.net/', ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejects(string $url, string $reason): void
    {
        $policy = $this->policy(['https://app.example.com']);

        self::assertFalse($policy->isAllowed($url));
        try {
            $policy->assertAllowed($url);
            self::fail("$url must be rejected");
        } catch (ReturnUrlRejectedException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($url, $e->url);
        }
    }

    public function testDefaultPortsAreTheSameOrigin(): void
    {
        $policy = $this->policy(['http://dev.example.com:80']);

        self::assertTrue($policy->isAllowed('https://shop.example.com:443/return'));
        self::assertTrue($policy->isAllowed('http://dev.example.com/return'));
    }

    /**
     * @param list<string> $origins
     */
    private function policy(array $origins = []): AllowListReturnUrlPolicy
    {
        return new AllowListReturnUrlPolicy(new FixedShopUrl(), new FixedOrigins($origins));
    }
}
