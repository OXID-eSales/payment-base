<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

use OxidEsales\PaymentBase\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentBase\Mcp\Http\HttpRequestFailedException;

/**
 * Keys come from the issuer's JWKS document, fetched through the injected
 * HTTP client and cached for a bounded time. A key rotated at the issuer is
 * picked up when the TTL ends; a failed or malformed fetch is an error and is
 * never cached (no keys, no token - there is no "accept anyway").
 *
 * Only RSA signature keys valid for RS256 are usable.
 */
final class JwksKeyProvider implements JwksKeyProviderInterface
{
    public const DEFAULT_TTL_SECONDS = 300;
    public const MAX_TTL_SECONDS = 3600;

    private const ALGORITHM = 'RS256';

    public function __construct(
        private readonly string $jwksUri,
        private readonly HttpClientInterface $http,
        private readonly JwksCacheInterface $cache,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if (!str_starts_with($jwksUri, 'https://')) {
            throw new \InvalidArgumentException('The JWKS URI must be an https URL');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException(sprintf('The JWKS cache TTL must be 1..%d seconds', self::MAX_TTL_SECONDS));
        }
    }

    public function publicKeyFor(string $kid): string
    {
        foreach ($this->keys() as $jwk) {
            if (($jwk['kid'] ?? null) !== $kid) {
                continue;
            }

            return $this->pemOf($jwk);
        }

        throw new InvalidAgentTokenException('No signing key with this kid');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keys(): array
    {
        $cacheKey = hash('sha256', $this->jwksUri);
        $document = $this->cache->get($cacheKey);
        if ($document === null) {
            $document = $this->fetch();
            $keys = $this->parse($document);
            $this->cache->set($cacheKey, $document, $this->ttlSeconds);

            return $keys;
        }

        return $this->parse($document);
    }

    private function fetch(): string
    {
        try {
            return $this->http->get($this->jwksUri);
        } catch (HttpRequestFailedException) {
            throw new InvalidAgentTokenException('The signing keys could not be fetched');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(string $document): array
    {
        $decoded = json_decode($document, true);
        if (!is_array($decoded) || !is_array($decoded['keys'] ?? null)) {
            throw new InvalidAgentTokenException('The signing key document is malformed');
        }

        $keys = [];
        foreach ($decoded['keys'] as $jwk) {
            if (is_array($jwk)) {
                /** @var array<string, mixed> $jwk */
                $keys[] = $jwk;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function pemOf(array $jwk): string
    {
        $alg = $jwk['alg'] ?? self::ALGORITHM;
        $use = $jwk['use'] ?? 'sig';
        if (($jwk['kty'] ?? null) !== 'RSA' || $alg !== self::ALGORITHM || $use !== 'sig') {
            throw new InvalidAgentTokenException('The signing key is not an RS256 signature key');
        }

        $modulus = $this->decodeComponent($jwk['n'] ?? null);
        $exponent = $this->decodeComponent($jwk['e'] ?? null);

        return RsaPublicKeyPem::fromComponents($modulus, $exponent);
    }

    private function decodeComponent(mixed $value): string
    {
        $bytes = is_string($value) ? Base64Url::decode($value) : null;
        if ($bytes === null || $bytes === '') {
            throw new InvalidAgentTokenException('The signing key is malformed');
        }

        return $bytes;
    }
}
