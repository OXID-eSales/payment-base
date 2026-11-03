<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Return;

use OxidEsales\PaymentBase\Checkout\Return\ContractReturnTokenService;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessSettingsInterface;
use OxidEsales\PaymentBase\Checkout\Return\ReturnTokenVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VR-20 R3 - the one return token for every provider: signed with a secret of its own (not
 * derived from a provider API key), expiring, naming the shopper it was issued to.
 */
final class ContractReturnTokenServiceTest extends TestCase
{
    private const TTL_SECONDS = 86400;

    public function testAFreshTokenIsValidForItsContract(): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));

        $token = $service->generateToken('contract-1', 'user-1');

        self::assertSame(ReturnTokenVerdict::Valid, $service->verify($token, 'contract-1', 'user-1'));
    }

    public function testATokenIsUrlSafe(): void
    {
        $token = $this->service(new InMemoryReturnTokenSecretStore('secret-one'))->generateToken('contract-1', 'user-1');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+\.[0-9a-f]+$/', $token);
    }

    public function testATokenExpiresAfterTheConfiguredTtl(): void
    {
        $store = new InMemoryReturnTokenSecretStore('secret-one');
        $token = $this->service($store, 1000)->generateToken('contract-1', 'user-1');

        self::assertSame(
            ReturnTokenVerdict::Valid,
            $this->service($store, 1000 + self::TTL_SECONDS)->verify($token, 'contract-1', 'user-1')
        );
        self::assertSame(
            ReturnTokenVerdict::Expired,
            $this->service($store, 1000 + self::TTL_SECONDS + 1)->verify($token, 'contract-1', 'user-1')
        );
    }

    public function testATokenForAnotherContractIsInvalid(): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));

        $token = $service->generateToken('contract-1', 'user-1');

        self::assertSame(ReturnTokenVerdict::Invalid, $service->verify($token, 'contract-2', 'user-1'));
    }

    public function testATokenIssuedToAnotherShopperIsInvalid(): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));

        $token = $service->generateToken('contract-1', 'user-1');

        self::assertSame(ReturnTokenVerdict::Invalid, $service->verify($token, 'contract-1', 'user-2'));
    }

    public function testATamperedPayloadIsInvalid(): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));
        [, $signature] = explode('.', $service->generateToken('contract-1', 'user-1'));
        $forgedPayload = rtrim(strtr(base64_encode(
            json_encode([1, 'contract-1', PHP_INT_MAX, 'user-1', 'key0'], JSON_THROW_ON_ERROR)
        ), '+/', '-_'), '=');

        self::assertSame(
            ReturnTokenVerdict::Invalid,
            $service->verify($forgedPayload . '.' . $signature, 'contract-1', 'user-1')
        );
    }

    public function testATamperedSignatureIsInvalid(): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));
        $token = $service->generateToken('contract-1', 'user-1');

        self::assertSame(ReturnTokenVerdict::Invalid, $service->verify(substr($token, 0, -1) . '0', 'contract-1', 'user-1'));
    }

    #[DataProvider('garbage')]
    public function testGarbageIsInvalid(string $token): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));

        self::assertSame(ReturnTokenVerdict::Invalid, $service->verify($token, 'contract-1', 'user-1'));
        self::assertNull($service->extractContractId($token));
    }

    /** @return iterable<string, array{string}> */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'no dot' => ['Y29udHJhY3QtMTpmZmY'];
        yield 'not base64' => ['***.abc'];
        yield 'not json' => ['bm90LWpzb24.abc'];
        yield 'too many dots' => ['a.b.c'];
    }

    public function testATokenSignedWithAnotherSecretIsInvalid(): void
    {
        $token = $this->service(new InMemoryReturnTokenSecretStore('secret-one'))->generateToken('contract-1', 'user-1');

        self::assertSame(
            ReturnTokenVerdict::Invalid,
            $this->service(new InMemoryReturnTokenSecretStore('secret-two'))->verify($token, 'contract-1', 'user-1')
        );
    }

    public function testAnOlderSecretStillVerifiesAfterRotationAndNewTokensUseTheNewOne(): void
    {
        $store = new InMemoryReturnTokenSecretStore('secret-one');
        $service = $this->service($store);
        $oldToken = $service->generateToken('contract-1', 'user-1');

        $store->add('key-rotated', 'secret-two');
        $newToken = $service->generateToken('contract-1', 'user-1');

        self::assertSame(ReturnTokenVerdict::Valid, $service->verify($oldToken, 'contract-1', 'user-1'));
        self::assertSame(ReturnTokenVerdict::Valid, $service->verify($newToken, 'contract-1', 'user-1'));
        self::assertNotSame($oldToken, $newToken);
    }

    public function testRetiringASecretRevokesItsTokens(): void
    {
        $store = new InMemoryReturnTokenSecretStore('secret-one');
        $service = $this->service($store);
        $oldToken = $service->generateToken('contract-1', 'user-1');
        $store->add('key-rotated', 'secret-two');

        $store->remove('key0');

        self::assertSame(ReturnTokenVerdict::Invalid, $service->verify($oldToken, 'contract-1', 'user-1'));
    }

    public function testTheContractIdOfAnAuthenticTokenCanBeRead(): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));

        self::assertSame('contract-1', $service->extractContractId($service->generateToken('contract-1', 'user-1')));
    }

    public function testTheContractIdOfAForgedTokenIsNotReturned(): void
    {
        $token = $this->service(new InMemoryReturnTokenSecretStore('secret-one'))->generateToken('contract-1', 'user-1');
        $other = $this->service(new InMemoryReturnTokenSecretStore('secret-two'));

        self::assertNull($other->extractContractId($token));
    }

    public function testATokenWithoutAShopperIsValidForAnyContractOwner(): void
    {
        $service = $this->service(new InMemoryReturnTokenSecretStore('secret-one'));

        $token = $service->generateToken('contract-1');

        self::assertSame(ReturnTokenVerdict::Valid, $service->verify($token, 'contract-1', 'user-9'));
    }

    private function service(InMemoryReturnTokenSecretStore $store, int $now = 1000): ContractReturnTokenService
    {
        $settings = $this->createStub(ReturnAccessSettingsInterface::class);
        $settings->method('tokenTtlSeconds')->willReturn(self::TTL_SECONDS);

        return new class ($store, $settings, $now) extends ContractReturnTokenService {
            public function __construct(
                InMemoryReturnTokenSecretStore $store,
                ReturnAccessSettingsInterface $settings,
                private readonly int $fixedNow,
            ) {
                parent::__construct($store, $settings);
            }

            protected function now(): int
            {
                return $this->fixedNow;
            }
        };
    }
}
