<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use OpenSSLAsymmetricKey;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealPrimitivesInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use RuntimeException;

/**
 * payment-base's own OpenSSL primitives: what keeps the sealed client config working on a shop without the
 * sealed-channel module.
 *
 * @since 1.5.0
 */
final class BuiltInSealPrimitives implements SealPrimitivesInterface
{
    private const CIPHER = 'aes-256-gcm';
    private const SECRET_BYTES = 32;
    private const TAG_BYTES = 16;
    private const COORDINATE_BYTES = 32;
    private const RAW_POINT_BYTES = 65;
    private const UNCOMPRESSED_POINT_MARKER = "\x04";
    // DER prefix of a SubjectPublicKeyInfo for an uncompressed P-256 point (id-ecPublicKey, prime256v1).
    private const SPKI_P256_PREFIX_HEX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function clientKey(string $rawPoint): OpenSSLAsymmetricKey
    {
        if (strlen($rawPoint) !== self::RAW_POINT_BYTES) {
            throw new InvalidClientPublicKey('expected an uncompressed point of 65 bytes');
        }
        if ($rawPoint[0] !== self::UNCOMPRESSED_POINT_MARKER) {
            throw new InvalidClientPublicKey('expected an uncompressed point (0x04 prefix)');
        }

        $der = (string) hex2bin(self::SPKI_P256_PREFIX_HEX) . $rawPoint;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

        // OpenSSL also validates that the point lies on the curve.
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            $this->drainOpenSslErrors();
            throw new InvalidClientPublicKey('not a point on curve P-256');
        }

        return $key;
    }

    public function sharedSecret(OpenSSLAsymmetricKey $serverKey, OpenSSLAsymmetricKey $clientKey): string
    {
        $secret = openssl_pkey_derive($clientKey, $serverKey, self::SECRET_BYTES);

        return $secret !== false ? $secret : throw new RuntimeException('ECDH key agreement failed');
    }

    public function rawPublicKey(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $curve = is_array($details) ? ($details['ec'] ?? null) : null;
        if (!is_array($curve) || !is_string($curve['x'] ?? null) || !is_string($curve['y'] ?? null)) {
            throw new RuntimeException('The ephemeral server key is not an EC key');
        }

        // OpenSSL may drop leading zero bytes of a coordinate; the raw point needs fixed width.
        return self::UNCOMPRESSED_POINT_MARKER
            . str_pad($curve['x'], self::COORDINATE_BYTES, "\0", STR_PAD_LEFT)
            . str_pad($curve['y'], self::COORDINATE_BYTES, "\0", STR_PAD_LEFT);
    }

    public function encrypt(string $plaintext, string $key, string $iv, string $aad): string
    {
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_BYTES);
        if ($encrypted === false) {
            throw new RuntimeException('AES-256-GCM encryption failed');
        }

        return $encrypted . $tag;
    }

    private function drainOpenSslErrors(): void
    {
        // A rejected key leaves messages in OpenSSL's queue that would surface in the next, unrelated call.
        while (openssl_error_string() !== false) {
            continue;
        }
    }
}
