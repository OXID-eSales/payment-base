<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use OpenSSLAsymmetricKey;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigSealerInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealInputsInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\ValueObject\SealedEnvelope;
use RuntimeException;

/**
 * Seals the public client config for ONE browser, exactly as specified in
 * docs/payments-keys.md of oxid-fe-user (envelope version 1):
 * ephemeral P-256 ECDH, HKDF-SHA256 (salt, info = aad) and AES-256-GCM with
 * aad, the tag appended. Standard OpenSSL primitives only.
 *
 * @since 1.4.0
 */
final class ClientConfigSealer implements ClientConfigSealerInterface
{
    private const VERSION = 1;
    private const ALGORITHM = 'ECDH-P256+HKDF-SHA256+A256GCM';
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const SALT_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const COORDINATE_BYTES = 32;
    private const RAW_POINT_BYTES = 65;
    private const UNCOMPRESSED_POINT_MARKER = "\x04";
    // DER prefix of a SubjectPublicKeyInfo for an uncompressed P-256 point (id-ecPublicKey, prime256v1).
    private const SPKI_P256_PREFIX_HEX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(private readonly SealInputsInterface $inputs)
    {
    }

    public function seal(string $providerId, string $tokenId, string $clientPublicKey, array $values): SealedEnvelope
    {
        $clientKey = $this->clientKey($clientPublicKey);
        $serverKey = $this->inputs->ephemeralPrivateKey();
        $issuedAt = $this->inputs->issuedAt();
        $aad = sprintf('oxid-pcc|v1|%s|%d|%s', $providerId, $issuedAt, $tokenId);
        $salt = $this->inputs->randomBytes(self::SALT_BYTES);
        $iv = $this->inputs->randomBytes(self::IV_BYTES);

        $key = hash_hkdf('sha256', $this->sharedSecret($serverKey, $clientKey), self::KEY_BYTES, $aad, $salt);
        $ciphertext = $this->encrypt(json_encode($values, JSON_THROW_ON_ERROR), $key, $iv, $aad);

        return new SealedEnvelope(
            self::VERSION,
            self::ALGORITHM,
            self::base64Url($this->rawPublicKey($serverKey)),
            self::base64Url($salt),
            self::base64Url($iv),
            $issuedAt,
            self::base64Url($ciphertext),
        );
    }

    private function clientKey(string $clientPublicKey): OpenSSLAsymmetricKey
    {
        $point = $this->decodeRawPoint($clientPublicKey);
        $der = (string) hex2bin(self::SPKI_P256_PREFIX_HEX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

        // OpenSSL also validates that the point lies on the curve.
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            $this->drainOpenSslErrors();
            throw new InvalidClientPublicKey('not a point on curve P-256');
        }

        return $key;
    }

    private function decodeRawPoint(string $clientPublicKey): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $clientPublicKey) !== 1) {
            throw new InvalidClientPublicKey('expected base64url without padding');
        }

        $point = base64_decode(strtr($clientPublicKey, '-_', '+/'), true);
        if ($point === false || self::base64Url($point) !== $clientPublicKey) {
            throw new InvalidClientPublicKey('expected base64url without padding');
        }
        if (strlen($point) !== self::RAW_POINT_BYTES) {
            throw new InvalidClientPublicKey('expected an uncompressed point of 65 bytes');
        }
        if ($point[0] !== self::UNCOMPRESSED_POINT_MARKER) {
            throw new InvalidClientPublicKey('expected an uncompressed point (0x04 prefix)');
        }

        return $point;
    }

    private function drainOpenSslErrors(): void
    {
        // A rejected key leaves messages in OpenSSL's queue that would surface in the next, unrelated call.
        while (openssl_error_string() !== false) {
            continue;
        }
    }

    private function sharedSecret(OpenSSLAsymmetricKey $serverKey, OpenSSLAsymmetricKey $clientKey): string
    {
        $secret = openssl_pkey_derive($clientKey, $serverKey, self::KEY_BYTES);

        return $secret !== false ? $secret : throw new RuntimeException('ECDH key agreement failed');
    }

    private function encrypt(string $plaintext, string $key, string $iv, string $aad): string
    {
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_BYTES);
        if ($encrypted === false) {
            throw new RuntimeException('AES-256-GCM encryption failed');
        }

        return $encrypted . $tag;
    }

    private function rawPublicKey(OpenSSLAsymmetricKey $key): string
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

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
