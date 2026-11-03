<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

/**
 * The one return token for every provider (replaces the Stripe and Mollie copies).
 *
 * Format: `base64url(json[version, contractId, expiresAt, userId|null, keyId]) . '.' . hmac`, the HMAC
 * (SHA-256) over the base64url part with the secret `keyId` names. The secret is the shop's own
 * ({@see ReturnTokenSecretStoreInterface}), not derived from a provider key. The `.` also tells
 * the new format from the providers' legacy `base64url(id:hmac)` tokens, which never contain one.
 */
class ContractReturnTokenService implements ContractReturnTokenServiceInterface
{
    private const VERSION = 1;
    private const ALGORITHM = 'sha256';
    private const SEPARATOR = '.';

    public function __construct(
        private readonly ReturnTokenSecretStoreInterface $secrets,
        private readonly ReturnAccessSettingsInterface $settings,
    ) {
    }

    public function generateToken(string $contractId, ?string $userId = null): string
    {
        $secret = $this->secrets->current();
        $payload = $this->encode(json_encode(
            [self::VERSION, $contractId, $this->now() + $this->settings->tokenTtlSeconds(), $userId, $secret->keyId],
            JSON_THROW_ON_ERROR
        ));

        return $payload . self::SEPARATOR . $this->sign($payload, $secret);
    }

    public function verify(string $token, string $contractId, string $contractUserId): ReturnTokenVerdict
    {
        $claims = $this->authenticClaims($token);
        if ($claims === null || $claims['contractId'] !== $contractId) {
            return ReturnTokenVerdict::Invalid;
        }
        if ($claims['userId'] !== null && $claims['userId'] !== $contractUserId) {
            return ReturnTokenVerdict::Invalid;
        }

        return $claims['expiresAt'] < $this->now() ? ReturnTokenVerdict::Expired : ReturnTokenVerdict::Valid;
    }

    public function extractContractId(string $token): ?string
    {
        return $this->authenticClaims($token)['contractId'] ?? null;
    }

    /**
     * Seam for tests: the clock.
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * @return array{contractId: string, expiresAt: int, userId: ?string}|null null unless the signature holds
     */
    private function authenticClaims(string $token): ?array
    {
        $parts = explode(self::SEPARATOR, $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;
        $claims = $this->decodeClaims($payload);
        $secret = $claims === null ? null : $this->secrets->find($claims['keyId']);
        if ($claims === null || $secret === null) {
            return null;
        }
        if (!hash_equals($this->sign($payload, $secret), $signature)) {
            return null;
        }

        return ['contractId' => $claims['contractId'], 'expiresAt' => $claims['expiresAt'], 'userId' => $claims['userId']];
    }

    /**
     * @return array{contractId: string, expiresAt: int, userId: ?string, keyId: string}|null
     */
    private function decodeClaims(string $payload): ?array
    {
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $decoded = $json === false ? null : json_decode($json, true);
        if (!is_array($decoded) || array_keys($decoded) !== [0, 1, 2, 3, 4]) {
            return null;
        }
        [$version, $contractId, $expiresAt, $userId, $keyId] = $decoded;
        if ($version !== self::VERSION || !is_string($contractId) || !is_int($expiresAt) || !is_string($keyId)) {
            return null;
        }
        if ($userId !== null && !is_string($userId)) {
            return null;
        }

        return ['contractId' => $contractId, 'expiresAt' => $expiresAt, 'userId' => $userId, 'keyId' => $keyId];
    }

    private function sign(string $payload, ReturnTokenSecret $secret): string
    {
        return hash_hmac(self::ALGORITHM, $payload, $secret->secret);
    }

    private function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
