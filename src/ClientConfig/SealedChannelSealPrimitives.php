<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealPrimitivesInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\SealedChannel\Crypto\GcmBox;
use OxidEsales\SealedChannel\Crypto\P256;

/**
 * The primitives of the sealed-channel module (SEAL-1), for the sealed client config: the platform's one
 * implementation of ECDH P-256 and AES-256-GCM. Only used when the module is installed (SealPrimitivesFactory);
 * the envelope (version 1, docs/payments-keys.md) is the same either way.
 *
 * @since 1.5.0
 */
final class SealedChannelSealPrimitives implements SealPrimitivesInterface
{
    public function clientKey(string $rawPoint): OpenSSLAsymmetricKey
    {
        try {
            return P256::publicKeyFromRaw($rawPoint);
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidClientPublicKey($invalid->getMessage());
        }
    }

    public function sharedSecret(OpenSSLAsymmetricKey $serverKey, OpenSSLAsymmetricKey $clientKey): string
    {
        return P256::sharedSecret($serverKey, $clientKey);
    }

    public function rawPublicKey(OpenSSLAsymmetricKey $key): string
    {
        return P256::rawPublicKey($key);
    }

    public function encrypt(string $plaintext, string $key, string $iv, string $aad): string
    {
        return GcmBox::encrypt($key, $iv, $aad, $plaintext);
    }
}
