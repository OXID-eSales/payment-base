<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\ClientConfigGuard;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnsafeClientConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The last line of defence of the sealed client config: whatever a provider
 * returns, a secret must never leave the shop towards a browser.
 */
final class ClientConfigGuardTest extends TestCase
{
    public function testLetsAllowListedPublicValuesThrough(): void
    {
        $values = ['publishableKey' => 'pk_test_123', 'testMode' => true, 'profileId' => 'pfl_abc'];

        self::assertSame($values, (new ClientConfigGuard())->assertPublic($values));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function unsafeConfigs(): array
    {
        return [
            'sk_ value' => [['publishableKey' => 'sk_live_abc']],
            'rk_ value' => [['publishableKey' => 'rk_live_abc']],
            'whsec_ value' => [['signing' => 'whsec_abc']],
            'secret key name' => [['clientSecret' => 'x']],
            'secret key name, upper case' => [['PAYPAL_SECRET' => 'x']],
            'apiKey name' => [['apiKey' => 'x']],
            'api_key name' => [['api_key' => 'x']],
            'api_key name, other case' => [['API_KEY' => 'x']],
            'int value' => [['timeout' => 30]],
            'null value' => [['clientId' => null]],
            'array value' => [['nested' => ['a' => 'b']]],
            'float value' => [['ratio' => 1.5]],
            'integer key' => [['pk_test_123']],
        ];
    }

    /**
     * @param array<mixed> $values
     */
    #[DataProvider('unsafeConfigs')]
    public function testRefusesAnythingThatLooksSecretOrIsNotStringOrBool(array $values): void
    {
        $this->expectException(UnsafeClientConfig::class);

        (new ClientConfigGuard())->assertPublic($values);
    }

    public function testTheRefusalNeverEchoesTheOffendingValue(): void
    {
        try {
            (new ClientConfigGuard())->assertPublic(['publishableKey' => 'sk_live_TOPSECRET']);
            self::fail('expected a refusal');
        } catch (UnsafeClientConfig $e) {
            self::assertStringNotContainsString('TOPSECRET', $e->getMessage());
            self::assertStringContainsString('publishableKey', $e->getMessage());
        }
    }
}
