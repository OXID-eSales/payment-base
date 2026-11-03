<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\ClientConfigGuard;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigSecretPatternProviderInterface;
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

        self::assertSame($values, $this->guard()->assertPublic($values));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function unsafeConfigs(): array
    {
        return [
            'secret key name' => [['clientSecret' => 'x']],
            'secret key name, upper case' => [['ACME_SECRET' => 'x']],
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

        $this->guard()->assertPublic($values);
    }

    public function testTheRefusalNeverEchoesTheOffendingValue(): void
    {
        try {
            $this->guard('/^acme_/')->assertPublic(['publishableKey' => 'acme_live_TOPSECRET']);
            self::fail('expected a refusal');
        } catch (UnsafeClientConfig $e) {
            self::assertStringNotContainsString('TOPSECRET', $e->getMessage());
            self::assertStringContainsString('publishableKey', $e->getMessage());
        }
    }

    public function testAssertPublic_ValueOfAnotherLengthThanTheContributedPattern_IsNotMistakenForAKey(): void
    {
        $values = ['label' => 'test_mode_enabled'];

        self::assertSame($values, $this->guard(self::KEY_PATTERN)->assertPublic($values));
    }

    public function testAssertPublic_NoPatternContributed_KnowsNoProviderSecretShape(): void
    {
        $values = ['publishableKey' => 'acme_live_abc'];

        self::assertSame($values, $this->guard()->assertPublic($values));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contributedSecretShapes(): array
    {
        return [
            'prefix pattern of the first provider' => ['acme_live_abc'],
            'key pattern of the second provider' => ['live_' . str_repeat('aB3', 10)],
            'key pattern, test mode' => ['test_' . str_repeat('x9', 15)],
        ];
    }

    #[DataProvider('contributedSecretShapes')]
    public function testAssertPublic_ValueMatchingAPatternOfAnyProvider_IsRefused(string $value): void
    {
        $guard = $this->guard('/^acme_/', self::KEY_PATTERN);

        $this->expectException(UnsafeClientConfig::class);

        $guard->assertPublic(['profileId' => $value]);
    }

    private const KEY_PATTERN = '/^(live|test)_[A-Za-z0-9]{30}$/';

    private function guard(string ...$secretPatterns): ClientConfigGuard
    {
        $providers = [];
        foreach ($secretPatterns as $pattern) {
            $providers[] = new class ($pattern) implements ClientConfigSecretPatternProviderInterface {
                public function __construct(private readonly string $pattern)
                {
                }

                public function secretValuePatterns(): array
                {
                    return [$this->pattern];
                }
            };
        }

        return new ClientConfigGuard($providers);
    }

    public function testAssertPublic_NameOutsideTheAllowList_IsRefusedEvenWithAHarmlessValue(): void
    {
        $this->expectException(UnsafeClientConfig::class);
        $this->expectExceptionMessage('allow-list');

        $this->guard()->assertPublic(['region' => 'eu'], ['publishableKey']);
    }

    public function testAssertPublic_OnlyAllowListedNames_PassesThrough(): void
    {
        $values = ['profileId' => 'pfl_abc', 'testMode' => true];

        self::assertSame($values, $this->guard()->assertPublic($values, ['profileId', 'testMode']));
    }

    public function testAssertPublic_EmptyAllowList_RefusesEveryValue(): void
    {
        $this->expectException(UnsafeClientConfig::class);

        $this->guard()->assertPublic(['profileId' => 'pfl_abc'], []);
    }

    public function testAssertPublic_AllowListedNameWithSecretShapedValue_IsStillRefused(): void
    {
        $this->expectException(UnsafeClientConfig::class);

        $this->guard('/^acme_/')->assertPublic(['publishableKey' => 'acme_live_abc'], ['publishableKey']);
    }

    public function testAssertPublic_WithoutAllowList_KeepsTheDenyShapesOnly(): void
    {
        $values = ['anything' => 'harmless'];

        self::assertSame($values, $this->guard()->assertPublic($values, null));
    }
}
