<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Test helper: P-256 keys from the JWKs of the shared test vector, and the
 * browser's half of the exchange (decrypting an envelope) in PHP.
 */
final class EcKeys
{
    private const SEC1_PRIVATE_KEY_HEADER = '30770201010420';
    private const P256_OID_AND_PUBLIC_KEY_HEADER = 'a00a06082a8648ce3d030107a144034200';

    public static function b64urlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    public static function b64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $jwk
     */
    public static function privateKeyFromJwk(array $jwk): OpenSSLAsymmetricKey
    {
        $der = hex2bin(self::SEC1_PRIVATE_KEY_HEADER)
            . self::b64urlDecode($jwk['d'])
            . hex2bin(self::P256_OID_AND_PUBLIC_KEY_HEADER)
            . "\x04" . self::b64urlDecode($jwk['x']) . self::b64urlDecode($jwk['y']);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";

        return openssl_pkey_get_private($pem) ?: throw new RuntimeException('bad test key');
    }

    public static function newPrivateKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
            ?: throw new RuntimeException('cannot create test key');
    }

    public static function rawPublicKey(OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /**
     * What the browser does: rebuild aad from ITS OWN provider and token id,
     * derive the key, decrypt. Returns null when authentication fails.
     *
     * @param array<string, mixed> $envelope
     */
    public static function open(
        array $envelope,
        OpenSSLAsymmetricKey $clientPrivateKey,
        string $provider,
        string $tokenId
    ): ?string {
        $aad = sprintf('oxid-pcc|v1|%s|%d|%s', $provider, $envelope['issuedAt'], $tokenId);
        $serverPublic = openssl_pkey_get_public(self::spki(self::b64urlDecode($envelope['serverPublicKey'])));
        $secret = openssl_pkey_derive($serverPublic, $clientPrivateKey, 32);
        $key = hash_hkdf('sha256', $secret, 32, $aad, self::b64urlDecode($envelope['salt']));
        $blob = self::b64urlDecode($envelope['ciphertext']);
        $plain = openssl_decrypt(
            substr($blob, 0, -16),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            self::b64urlDecode($envelope['iv']),
            substr($blob, -16),
            $aad
        );

        return $plain === false ? null : $plain;
    }

    public static function spki(string $rawPoint): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawPoint;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
