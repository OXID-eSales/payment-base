<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\ReturnUrl;

use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlRejectedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * graphql-base logs every GraphQL error message at error level; the message
 * therefore carries the origin of a rejected URL, never its path, query or
 * credentials.
 */
final class ReturnUrlRejectedExceptionTest extends TestCase
{
    public function testMessage_RejectedUrl_ContainsOriginOnly(): void
    {
        $e = new ReturnUrlRejectedException(
            'https://evil.example.net/return?session_id=cs_test_123&token=abc#frag',
            ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED
        );

        self::assertSame('Return URL rejected (origin_not_allowed): https://evil.example.net', $e->getMessage());
    }

    public function testMessage_NonDefaultPort_KeepsThePort(): void
    {
        $e = new ReturnUrlRejectedException('http://localhost:5173/cb?uid=1', ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED);

        self::assertSame('Return URL rejected (origin_not_allowed): http://localhost:5173', $e->getMessage());
    }

    public function testMessage_UrlWithCredentials_NeverContainsThem(): void
    {
        $e = new ReturnUrlRejectedException('https://user:pw@shop.example.com/x', ReturnUrlRejectedException::CREDENTIALS_NOT_ALLOWED);

        self::assertSame('Return URL rejected (credentials_not_allowed): https://shop.example.com', $e->getMessage());
        self::assertStringNotContainsString('pw', $e->getMessage());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function urlsWithoutOrigin(): array
    {
        return [
            'relative path with query' => ['/thank-you?contract_id=c1', '(no origin)'],
            'empty' => ['', '(no origin)'],
            'javascript' => ['javascript:alert(document.cookie)', 'javascript:'],
            'data' => ['data:text/html,secret', 'data:'],
            'unparseable' => ['http:///?uid=1', '(no origin)'],
        ];
    }

    #[DataProvider('urlsWithoutOrigin')]
    public function testMessage_UrlWithoutHost_NamesNothingOfTheUrl(string $url, string $expectedOrigin): void
    {
        $e = new ReturnUrlRejectedException($url, ReturnUrlRejectedException::NOT_ABSOLUTE);

        self::assertSame('Return URL rejected (not_absolute): ' . $expectedOrigin, $e->getMessage());
    }

    public function testFullUrl_AnyRejectedUrl_IsAvailableForDebugLogging(): void
    {
        $url = 'https://evil.example.net/return?session_id=cs_test_123';

        $e = new ReturnUrlRejectedException($url, ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED);

        self::assertSame($url, $e->fullUrl());
    }
}
