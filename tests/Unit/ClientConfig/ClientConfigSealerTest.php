<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\ClientConfigSealer;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\SystemSealInputs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the envelope of docs/payments-keys.md (version 1) byte-for-byte to the
 * vector produced with Node's crypto, which oxid-fe-core decrypts with WebCrypto.
 */
final class ClientConfigSealerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $vector;

    protected function setUp(): void
    {
        $this->vector = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/sealed-config-vector.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function vectorSealer(): ClientConfigSealer
    {
        return new ClientConfigSealer(new FixedSealInputs(
            EcKeys::privateKeyFromJwk($this->vector['serverPrivateKeyJwk']),
            [
                32 => EcKeys::b64urlDecode($this->vector['salt']),
                12 => EcKeys::b64urlDecode($this->vector['iv']),
            ],
            $this->vector['issuedAt']
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function sealVector(): array
    {
        $envelope = $this->vectorSealer()->seal(
            $this->vector['provider'],
            $this->vector['tokenId'],
            $this->vector['clientPublicKey'],
            ['clientId' => 'VECTOR-NOT-A-REAL-KEY']
        );

        return get_object_vars($envelope);
    }

    public function testReproducesTheSharedTestVectorExactly(): void
    {
        self::assertSame($this->vector['envelope'], $this->sealVector());
    }

    public function testTheBrowserSideCanOpenWhatWasSealed(): void
    {
        $plain = EcKeys::open(
            $this->sealVector(),
            EcKeys::privateKeyFromJwk($this->vector['clientPrivateKeyJwk']),
            'paypal',
            'vector-token-id'
        );

        self::assertSame($this->vector['plaintext'], $plain);
    }

    public function testATamperedCiphertextByteIsDetected(): void
    {
        $envelope = $this->sealVector();
        $blob = EcKeys::b64urlDecode($envelope['ciphertext']);
        $blob[0] = chr(ord($blob[0]) ^ 0x01);
        $envelope['ciphertext'] = EcKeys::b64urlEncode($blob);

        self::assertNull($this->openAsVectorClient($envelope, 'paypal', 'vector-token-id'));
    }

    public function testTheEnvelopeIsBoundToProviderAndToken(): void
    {
        $envelope = $this->sealVector();

        self::assertNull($this->openAsVectorClient($envelope, 'stripe', 'vector-token-id'));
        self::assertNull($this->openAsVectorClient($envelope, 'paypal', 'another-token'));
    }

    public function testTheEnvelopeIsBoundToIssuedAt(): void
    {
        $envelope = $this->sealVector();
        $envelope['issuedAt']++;

        self::assertNull($this->openAsVectorClient($envelope, 'paypal', 'vector-token-id'));
    }

    public function testAnEmptyTokenIdIsSealedAsAnEmptyString(): void
    {
        $envelope = get_object_vars($this->vectorSealer()->seal(
            'paypal',
            '',
            $this->vector['clientPublicKey'],
            ['clientId' => 'x']
        ));

        self::assertNotNull($this->openAsVectorClient($envelope, 'paypal', ''));
    }

    public function testRandomSealsDiffer(): void
    {
        $sealer = new ClientConfigSealer(new SystemSealInputs());
        $client = $this->vector['clientPublicKey'];

        $first = $sealer->seal('paypal', 't', $client, ['clientId' => 'x']);
        $second = $sealer->seal('paypal', 't', $client, ['clientId' => 'x']);

        self::assertNotSame($first->ciphertext, $second->ciphertext);
        self::assertNotSame($first->serverPublicKey, $second->serverPublicKey);
        self::assertNotSame($first->salt, $second->salt);
        self::assertNotSame($first->iv, $second->iv);
        self::assertSame(32, strlen(EcKeys::b64urlDecode($first->salt)));
        self::assertSame(12, strlen(EcKeys::b64urlDecode($first->iv)));
        self::assertSame(65, strlen(EcKeys::b64urlDecode($first->serverPublicKey)));
    }

    public function testRandomSealsOpenWithAFreshBrowserKey(): void
    {
        $browserKey = EcKeys::newPrivateKey();
        $sealer = new ClientConfigSealer(new SystemSealInputs());

        $envelope = get_object_vars($sealer->seal(
            'mollie',
            'tok',
            EcKeys::b64urlEncode(EcKeys::rawPublicKey($browserKey)),
            ['profileId' => 'pfl_1', 'testMode' => true]
        ));

        self::assertSame(
            '{"profileId":"pfl_1","testMode":true}',
            EcKeys::open($envelope, $browserKey, 'mollie', 'tok')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badClientKeys(): array
    {
        $point = str_repeat("\x01", 64);

        return [
            'empty' => [''],
            'not base64url' => ['***not*base64url***'],
            'standard base64 alphabet' => [base64_encode("\x04" . str_repeat("\xfb", 64))],
            'padded' => [base64_encode("\x04" . $point) . '='],
            'too short (64 bytes)' => [EcKeys::b64urlEncode($point)],
            'too long (66 bytes)' => [EcKeys::b64urlEncode("\x04" . $point . "\x01")],
            'compressed point (33 bytes)' => [EcKeys::b64urlEncode("\x02" . str_repeat("\x01", 32))],
            'wrong prefix byte' => [EcKeys::b64urlEncode("\x05" . $point)],
            'point not on the curve' => [EcKeys::b64urlEncode("\x04" . $point)],
        ];
    }

    #[DataProvider('badClientKeys')]
    public function testRejectsAnythingButARawP256Point(string $clientPublicKey): void
    {
        $this->expectException(InvalidClientPublicKey::class);

        $this->vectorSealer()->seal('paypal', 't', $clientPublicKey, ['clientId' => 'x']);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function openAsVectorClient(array $envelope, string $provider, string $tokenId): ?string
    {
        return EcKeys::open(
            $envelope,
            EcKeys::privateKeyFromJwk($this->vector['clientPrivateKeyJwk']),
            $provider,
            $tokenId
        );
    }
}
