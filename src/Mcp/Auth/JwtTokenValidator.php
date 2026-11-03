<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

use OxidEsales\PaymentBase\Mcp\AgentContext;
use OxidEsales\PaymentBase\Mcp\AgentContextInterface;

/**
 * Turns an agent's bearer JWT into an AgentContext, or refuses it.
 *
 * Order of checks: shape and size, the pinned algorithm (RS256 only: `none`,
 * HMAC and every other value are refused before any key is looked at), the
 * signature against the issuer's JWKS key for the token's `kid`, then expiry,
 * not-before, issuer, audience and subject. No clock leeway: tokens are short
 * lived and the issuer's and the shop's clocks are NTP-synced.
 */
final class JwtTokenValidator
{
    private const MAX_TOKEN_BYTES = 8192;
    private const ALGORITHM = 'RS256';
    private const SEGMENTS = 3;

    public function __construct(
        private readonly JwksKeyProviderInterface $keys,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidAgentTokenException
     */
    public function authenticate(string $jwt): AgentContextInterface
    {
        $segments = explode('.', $jwt);
        if (strlen($jwt) > self::MAX_TOKEN_BYTES || count($segments) !== self::SEGMENTS) {
            throw new InvalidAgentTokenException('The token is malformed');
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;

        $header = $this->decodeObject($encodedHeader);
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new InvalidAgentTokenException('The token algorithm is not accepted');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw new InvalidAgentTokenException('The token names no signing key');
        }

        $this->assertSignature($encodedHeader . '.' . $encodedClaims, $encodedSignature, $kid);
        $claims = $this->decodeObject($encodedClaims);
        $this->assertClaims($claims);

        /** @var string $subject */
        $subject = $claims['sub'];

        return new AgentContext($subject, $jwt);
    }

    private function assertSignature(string $signingInput, string $encodedSignature, string $kid): void
    {
        $signature = Base64Url::decode($encodedSignature);
        if ($signature === null) {
            throw new InvalidAgentTokenException('The token signature is malformed');
        }

        $valid = openssl_verify($signingInput, $signature, $this->keys->publicKeyFor($kid), OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            throw new InvalidAgentTokenException('The token signature is invalid');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertClaims(array $claims): void
    {
        $now = $this->clock->now();
        $expiry = $claims['exp'] ?? null;
        if (!is_int($expiry)) {
            throw new InvalidAgentTokenException('The token has no expiry');
        }
        if ($expiry <= $now) {
            throw new InvalidAgentTokenException('The token expired');
        }
        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now)) {
            throw new InvalidAgentTokenException('The token is not yet valid');
        }
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new InvalidAgentTokenException('The token issuer is not accepted');
        }
        if (!$this->audienceMatches($claims['aud'] ?? null)) {
            throw new InvalidAgentTokenException('The token audience is not accepted');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new InvalidAgentTokenException('The token has no subject');
        }
    }

    private function audienceMatches(mixed $audience): bool
    {
        if (is_string($audience)) {
            return $audience === $this->audience;
        }

        return is_array($audience) && in_array($this->audience, $audience, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $segment): array
    {
        $json = Base64Url::decode($segment);
        $decoded = $json === null ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            throw new InvalidAgentTokenException('The token is malformed');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
