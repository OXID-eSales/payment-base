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
use OxidEsales\GraphQL\Base\Exception\Error;
use OxidEsales\GraphQL\Base\Exception\ErrorCategories;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigPublisherInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\TokenIdReaderInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;
use OxidEsales\PaymentBase\ClientConfig\SystemSealInputs;
use OxidEsales\PaymentBase\ClientConfig\ValueObject\SealedEnvelope;
use OxidEsales\PaymentBase\GraphQL\Controller\PaymentClientConfig;
use OxidEsales\PaymentBase\Tests\Unit\ClientConfig\EcKeys;
use OxidEsales\PaymentBase\Tests\Unit\ClientConfig\FakeClientConfigProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    /**
     * The client-visible answer must not change since the domain exceptions stopped being graphql-base errors:
     * headless clients (oxid-fe-user, hydroxid) match on the message and the category. These never carried an
     * `errorCode` extension, and still do not.
     */
    public function testAnUnknownProviderIsRefusedWithTheSameClientVisibleError(): void
    {
        $error = $this->refusal(fn () => $this->controller('t')->paymentClientConfig('klarna', 'x'));

        self::assertSame('Unknown payment provider "klarna"', $error->getMessage());
        self::assertSame(ErrorCategories::REQUESTERROR, $error->getCategory());
        self::assertTrue($error->isClientSafe());
        self::assertSame([], $error->getExtensions() ?? []);
        self::assertInstanceOf(UnknownClientConfigProvider::class, $error->getPrevious());
    }

    public function testAMalformedClientKeyIsRefusedWithTheSameClientVisibleError(): void
    {
        $error = $this->refusal(fn () => $this->controller('t')->paymentClientConfig('stripe', 'AAAA'));

        self::assertSame('Invalid clientPublicKey: expected an uncompressed point of 65 bytes', $error->getMessage());
        self::assertSame(ErrorCategories::REQUESTERROR, $error->getCategory());
        self::assertTrue($error->isClientSafe());
        self::assertSame([], $error->getExtensions() ?? []);
        self::assertInstanceOf(InvalidClientPublicKey::class, $error->getPrevious());
    }

    public function testAnUnexpectedFailureIsNotTurnedIntoAClientSafeError(): void
    {
        $this->expectException(RuntimeException::class);

        $controller = new PaymentClientConfig(
            new class implements ClientConfigPublisherInterface {
                public function sealedFor(string $providerId, string $clientPublicKey, string $tokenId): SealedEnvelope
                {
                    throw new RuntimeException('provider exploded');
                }
            },
            new FixedTokenId('t')
        );
        $controller->paymentClientConfig('stripe', 'x');
    }

    private function refusal(callable $call): Error
    {
        try {
            $call();
        } catch (Error $error) {
            return $error;
        }
        self::fail('the query was not refused with a GraphQL error');
    }
}
