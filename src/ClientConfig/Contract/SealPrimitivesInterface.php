<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

use OpenSSLAsymmetricKey;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;

/**
 * The cryptographic primitives of the sealed client config (docs/payments-keys.md): ECDH on P-256 and AES-256-GCM, on
 * OpenSSL. Two implementations, one result: payment-base's own (it must work alone) and those of the sealed-channel module
 * (SEAL-1), used when that module is installed, so the platform has one implementation of the primitives.
 *
 * @since 1.5.0
 */
interface SealPrimitivesInterface
{
    /**
     * @param string $rawPoint the browser's uncompressed point, 65 bytes (0x04 || X || Y)
     *
     * @throws InvalidClientPublicKey when it is not such a point on the curve
     */
    public function clientKey(string $rawPoint): OpenSSLAsymmetricKey;

    /**
     * @return string the 32-byte ECDH secret
     */
    public function sharedSecret(OpenSSLAsymmetricKey $serverKey, OpenSSLAsymmetricKey $clientKey): string;

    /**
     * @return string the uncompressed point of the key, 65 bytes
     */
    public function rawPublicKey(OpenSSLAsymmetricKey $key): string;

    /**
     * @return string the ciphertext with the 16-byte tag appended
     */
    public function encrypt(string $plaintext, string $key, string $iv, string $aad): string;
}
