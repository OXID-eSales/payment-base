<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\ClientConfigGuard;
use OxidEsales\PaymentBase\ClientConfig\ClientConfigProviderRegistry;
use OxidEsales\PaymentBase\ClientConfig\ClientConfigPublisher;
use OxidEsales\PaymentBase\ClientConfig\ClientConfigSealer;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnsafeClientConfig;
use OxidEsales\PaymentBase\ClientConfig\SystemSealInputs;
use PHPUnit\Framework\TestCase;

/**
 * provider id -> allow-listed values -> guard -> sealed for the asking browser.
 */
final class ClientConfigPublisherTest extends TestCase
{
    private function publisher(ClientConfigProviderInterface ...$providers): ClientConfigPublisher
    {
        return new ClientConfigPublisher(
            new ClientConfigProviderRegistry($providers),
            new ClientConfigGuard(),
            new ClientConfigSealer(new SystemSealInputs())
        );
    }

    public function testSealsExactlyTheValuesOfTheRequestedProvider(): void
    {
        $browserKey = EcKeys::newPrivateKey();
        $publisher = $this->publisher(
            new FakeClientConfigProvider('stripe', ['publishableKey' => 'pk_test_1']),
            new FakeClientConfigProvider('paypal', ['clientId' => 'abc'])
        );

        $envelope = $publisher->sealedFor('paypal', EcKeys::b64urlEncode(EcKeys::rawPublicKey($browserKey)), 'tok');

        self::assertSame(
            '{"clientId":"abc"}',
            EcKeys::open(get_object_vars($envelope), $browserKey, 'paypal', 'tok')
        );
    }

    public function testAnUnknownProviderIsRefused(): void
    {
        $this->expectException(UnknownClientConfigProvider::class);

        $this->publisher()->sealedFor('mollie', 'irrelevant', 'tok');
    }

    public function testASecretReturnedByAProviderIsNeverSealed(): void
    {
        $publisher = $this->publisher(new FakeClientConfigProvider('stripe', ['publishableKey' => 'sk_live_oops']));

        $this->expectException(UnsafeClientConfig::class);

        $publisher->sealedFor('stripe', EcKeys::b64urlEncode(EcKeys::rawPublicKey(EcKeys::newPrivateKey())), 'tok');
    }

    public function testSealedFor_ProviderDeclaresNames_RefusesAnUndeclaredNameWithAHarmlessValue(): void
    {
        $publisher = $this->publisher(
            new AllowListedFakeClientConfigProvider('paypal', ['clientId' => 'abc', 'region' => 'eu'], ['clientId'])
        );

        $this->expectException(UnsafeClientConfig::class);

        $publisher->sealedFor('paypal', EcKeys::b64urlEncode(EcKeys::rawPublicKey(EcKeys::newPrivateKey())), 'tok');
    }

    public function testSealedFor_ProviderDeclaresNames_SealsTheDeclaredValues(): void
    {
        $browserKey = EcKeys::newPrivateKey();
        $publisher = $this->publisher(
            new AllowListedFakeClientConfigProvider('mollie', ['profileId' => 'pfl_abc', 'testMode' => true], ['profileId', 'testMode'])
        );

        $envelope = $publisher->sealedFor('mollie', EcKeys::b64urlEncode(EcKeys::rawPublicKey($browserKey)), 'tok');

        self::assertSame(
            '{"profileId":"pfl_abc","testMode":true}',
            EcKeys::open(get_object_vars($envelope), $browserKey, 'mollie', 'tok')
        );
    }

    public function testSealedFor_ProviderWithoutDeclaredNames_KeepsWorkingUnderTheDenyShapes(): void
    {
        $browserKey = EcKeys::newPrivateKey();
        $publisher = $this->publisher(new FakeClientConfigProvider('legacy', ['someKey' => 'harmless']));

        $envelope = $publisher->sealedFor('legacy', EcKeys::b64urlEncode(EcKeys::rawPublicKey($browserKey)), 'tok');

        self::assertSame('{"someKey":"harmless"}', EcKeys::open(get_object_vars($envelope), $browserKey, 'legacy', 'tok'));
    }
}
