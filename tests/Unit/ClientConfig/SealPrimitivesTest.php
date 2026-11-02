<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\BuiltInSealPrimitives;
use OxidEsales\PaymentBase\ClientConfig\ClientConfigSealer;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealPrimitivesInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\SealedChannelSealPrimitives;
use OxidEsales\PaymentBase\ClientConfig\SealPrimitivesFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sealing of the client config runs on one of two sets of primitives (ECDH P-256, AES-256-GCM): its own, which keeps
 * payment-base working alone, and those of the sealed-channel module (SEAL-1) when that module is installed. Both must
 * produce the byte-identical envelope of docs/payments-keys.md.
 */
final class SealPrimitivesTest extends TestCase
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

    /**
     * @return array<string, array{class-string<SealPrimitivesInterface>}>
     */
    public static function primitives(): array
    {
        return [
            'its own' => [BuiltInSealPrimitives::class],
            'those of the sealed channel module' => [SealedChannelSealPrimitives::class],
        ];
    }

    /**
     * @param class-string<SealPrimitivesInterface> $class
     */
    private function primitivesOrSkip(string $class): SealPrimitivesInterface
    {
        if ($class === SealedChannelSealPrimitives::class && !SealPrimitivesFactory::sealedChannelAvailable()) {
            $this->markTestSkipped('oxid-esales/sealed-channel is not installed');
        }

        return new $class();
    }

    /**
     * @param class-string<SealPrimitivesInterface> $class
     */
    #[DataProvider('primitives')]
    public function testBothReproduceTheSharedVectorExactly(string $class): void
    {
        $sealer = new ClientConfigSealer(
            new FixedSealInputs(
                EcKeys::privateKeyFromJwk($this->vector['serverPrivateKeyJwk']),
                [
                    32 => EcKeys::b64urlDecode($this->vector['salt']),
                    12 => EcKeys::b64urlDecode($this->vector['iv']),
                ],
                $this->vector['issuedAt']
            ),
            $this->primitivesOrSkip($class)
        );

        $envelope = $sealer->seal(
            $this->vector['provider'],
            $this->vector['tokenId'],
            $this->vector['clientPublicKey'],
            ['clientId' => 'VECTOR-NOT-A-REAL-KEY']
        );

        self::assertSame($this->vector['envelope'], get_object_vars($envelope));
    }

    /**
     * @param class-string<SealPrimitivesInterface> $class
     */
    #[DataProvider('primitives')]
    public function testBothRefuseAPointThatIsNotOnTheCurve(string $class): void
    {
        $this->expectException(InvalidClientPublicKey::class);
        $this->primitivesOrSkip($class)->clientKey("\x04" . str_repeat("\x01", 64));
    }

    /**
     * @param class-string<SealPrimitivesInterface> $class
     */
    #[DataProvider('primitives')]
    public function testBothRefuseAnythingButAnUncompressedPoint(string $class): void
    {
        $this->expectException(InvalidClientPublicKey::class);
        $this->primitivesOrSkip($class)->clientKey('too short');
    }

    public function testTheFactoryTakesTheSealedChannelWhenItIsThereAndItsOwnOtherwise(): void
    {
        self::assertInstanceOf(BuiltInSealPrimitives::class, SealPrimitivesFactory::create(false));
        self::assertInstanceOf(SealedChannelSealPrimitives::class, SealPrimitivesFactory::create(true));
    }

    public function testTheDefaultFollowsWhatIsInstalled(): void
    {
        self::assertInstanceOf(
            SealPrimitivesFactory::sealedChannelAvailable() ? SealedChannelSealPrimitives::class : BuiltInSealPrimitives::class,
            SealPrimitivesFactory::create()
        );
    }
}
