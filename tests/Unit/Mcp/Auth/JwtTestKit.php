<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentBase\Mcp\Auth\ClockInterface;
use OxidEsales\PaymentBase\Mcp\Auth\JwksCacheInterface;
use OxidEsales\PaymentBase\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentBase\Mcp\Http\HttpRequestFailedException;

/**
 * Real RSA keys and compact JWS built in-process: no network, no fixtures.
 */
final class RsaSigner
{
    private \OpenSSLAsymmetricKey $key;

    public function __construct(public readonly string $kid)
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new \RuntimeException('openssl cannot generate a test key');
        }
        $this->key = $key;
    }

    /**
     * @return array<string, string>
     */
    public function jwk(string $alg = 'RS256', string $use = 'sig'): array
    {
        $rsa = openssl_pkey_get_details($this->key)['rsa'];

        return [
            'kty' => 'RSA',
            'kid' => $this->kid,
            'alg' => $alg,
            'use' => $use,
            'n' => self::b64($rsa['n']),
            'e' => self::b64($rsa['e']),
        ];
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $headerOverrides
     */
    public function token(array $claims, array $headerOverrides = []): string
    {
        $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid], $headerOverrides);
        $signingInput = self::b64((string) json_encode($header)) . '.' . self::b64((string) json_encode($claims));
        openssl_sign($signingInput, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . self::b64($signature);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

final class FakeClock implements ClockInterface
{
    public function __construct(public int $now = 1_800_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}

final class ArrayJwksCache implements JwksCacheInterface
{
    /** @var array<string, array{string, int}> */
    private array $entries = [];

    public function __construct(private readonly FakeClock $clock)
    {
    }

    public function get(string $key): ?string
    {
        if (!isset($this->entries[$key]) || $this->entries[$key][1] <= $this->clock->now()) {
            return null;
        }

        return $this->entries[$key][0];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->entries[$key] = [$value, $this->clock->now() + $ttlSeconds];
    }
}

final class FakeHttpClient implements HttpClientInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $urls = [];

    public function __construct(public string $body = '{"keys":[]}', public bool $fails = false)
    {
    }

    public function get(string $url): string
    {
        ++$this->calls;
        $this->urls[] = $url;
        if ($this->fails) {
            throw new HttpRequestFailedException('down');
        }

        return $this->body;
    }
}
