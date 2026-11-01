<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigSealerInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealInputsInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealPrimitivesInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\ValueObject\SealedEnvelope;

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
    private const KEY_BYTES = 32;
    private const SALT_BYTES = 32;
    private const IV_BYTES = 12;

    private readonly SealPrimitivesInterface $primitives;

    public function __construct(
        private readonly SealInputsInterface $inputs,
        ?SealPrimitivesInterface $primitives = null
    ) {
        // the sealed-channel module's primitives when it is installed (SEAL-1), payment-base's own otherwise
        $this->primitives = $primitives ?? SealPrimitivesFactory::create();
    }

    public function seal(string $providerId, string $tokenId, string $clientPublicKey, array $values): SealedEnvelope
    {
        $clientKey = $this->primitives->clientKey($this->decodeRawPoint($clientPublicKey));
        $serverKey = $this->inputs->ephemeralPrivateKey();
        $issuedAt = $this->inputs->issuedAt();
        $aad = sprintf('oxid-pcc|v1|%s|%d|%s', $providerId, $issuedAt, $tokenId);
        $salt = $this->inputs->randomBytes(self::SALT_BYTES);
        $iv = $this->inputs->randomBytes(self::IV_BYTES);

        $secret = $this->primitives->sharedSecret($serverKey, $clientKey);
        $key = hash_hkdf('sha256', $secret, self::KEY_BYTES, $aad, $salt);
        $ciphertext = $this->primitives->encrypt(json_encode($values, JSON_THROW_ON_ERROR), $key, $iv, $aad);

        return new SealedEnvelope(
            self::VERSION,
            self::ALGORITHM,
            self::base64Url($this->primitives->rawPublicKey($serverKey)),
            self::base64Url($salt),
            self::base64Url($iv),
            $issuedAt,
            self::base64Url($ciphertext),
        );
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

        return $point;
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
