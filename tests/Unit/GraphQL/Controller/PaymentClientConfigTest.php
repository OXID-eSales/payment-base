<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Controller;

use OxidEsales\PaymentBase\ClientConfig\ClientConfigGuard;
use OxidEsales\PaymentBase\ClientConfig\ClientConfigProviderRegistry;
use OxidEsales\PaymentBase\ClientConfig\ClientConfigPublisher;
use OxidEsales\PaymentBase\ClientConfig\ClientConfigSealer;
use OxidEsales\PaymentBase\ClientConfig\Contract\TokenIdReaderInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;
use OxidEsales\PaymentBase\ClientConfig\SystemSealInputs;
use OxidEsales\PaymentBase\GraphQL\Controller\PaymentClientConfig;
use OxidEsales\PaymentBase\Tests\Unit\ClientConfig\EcKeys;
use OxidEsales\PaymentBase\Tests\Unit\ClientConfig\FakeClientConfigProvider;
use PHPUnit\Framework\TestCase;

final class FixedTokenId implements TokenIdReaderInterface
{
    public function __construct(private readonly string $tokenId)
    {
    }

    public function tokenId(): string
    {
        return $this->tokenId;
    }
}

/**
 * The `paymentClientConfig` query, through a fake provider and the real
 * guard and sealer.
 */
final class PaymentClientConfigTest extends TestCase
{
    private function controller(string $tokenId): PaymentClientConfig
    {
        return new PaymentClientConfig(
            new ClientConfigPublisher(
                new ClientConfigProviderRegistry([
                    new FakeClientConfigProvider('stripe', ['publishableKey' => 'pk_test_FAKE']),
                ]),
                new ClientConfigGuard(),
                new ClientConfigSealer(new SystemSealInputs())
            ),
            new FixedTokenId($tokenId)
        );
    }

    public function testAnswersTheEnvelopeBoundToTheBearerTokensId(): void
    {
        $browserKey = EcKeys::newPrivateKey();

        $result = $this->controller('jwt-token-id')->paymentClientConfig(
            'stripe',
            EcKeys::b64urlEncode(EcKeys::rawPublicKey($browserKey))
        );

        self::assertSame(1, $result->v());
        self::assertSame('ECDH-P256+HKDF-SHA256+A256GCM', $result->alg());
        self::assertIsInt($result->issuedAt());
        $envelope = [
            'serverPublicKey' => $result->serverPublicKey(),
            'salt' => $result->salt(),
            'iv' => $result->iv(),
            'issuedAt' => $result->issuedAt(),
            'ciphertext' => $result->ciphertext(),
        ];
        self::assertSame(
            '{"publishableKey":"pk_test_FAKE"}',
            EcKeys::open($envelope, $browserKey, 'stripe', 'jwt-token-id')
        );
        self::assertNull(EcKeys::open($envelope, $browserKey, 'stripe', 'someone-elses-token'));
    }

    public function testATokenWithoutIdIsSealedWithAnEmptyTokenId(): void
    {
        $browserKey = EcKeys::newPrivateKey();

        $result = $this->controller('')->paymentClientConfig('stripe', EcKeys::b64urlEncode(EcKeys::rawPublicKey($browserKey)));

        $envelope = [
            'serverPublicKey' => $result->serverPublicKey(),
            'salt' => $result->salt(),
            'iv' => $result->iv(),
            'issuedAt' => $result->issuedAt(),
            'ciphertext' => $result->ciphertext(),
        ];
        self::assertNotNull(EcKeys::open($envelope, $browserKey, 'stripe', ''));
    }

    public function testAnUnknownProviderIsRefused(): void
    {
        $this->expectException(UnknownClientConfigProvider::class);

        $this->controller('t')->paymentClientConfig('klarna', 'x');
    }

    public function testAMalformedClientKeyIsRefused(): void
    {
        $this->expectException(InvalidClientPublicKey::class);

        $this->controller('t')->paymentClientConfig('stripe', 'AAAA');
    }
}
