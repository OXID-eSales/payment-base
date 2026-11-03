<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentBase\Mcp\Auth\InvalidAgentTokenException;
use OxidEsales\PaymentBase\Mcp\Auth\JwksKeyProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/JwtTestKit.php';

/**
 * The keys are fetched once per TTL through the injected client, cached, and
 * only usable RS256 signature keys of the asked kid are ever returned.
 */
final class JwksKeyProviderTest extends TestCase
{
    private const URI = 'https://idp.example.com/.well-known/jwks.json';

    private FakeClock $clock;
    private RsaSigner $signer;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->signer = new RsaSigner('key-1');
    }

    public function testFetchesTheJwksOnceAndServesFurtherLookupsFromTheCache(): void
    {
        $http = $this->http([$this->signer->jwk()]);
        $provider = $this->provider($http);

        $first = $provider->publicKeyFor('key-1');
        $second = $provider->publicKeyFor('key-1');

        self::assertSame(1, $http->calls);
        self::assertSame([self::URI], $http->urls);
        self::assertSame($first, $second);
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $first);
    }

    public function testCacheExpiresAfterTheTtlAndTheKeysAreFetchedAgain(): void
    {
        $http = $this->http([$this->signer->jwk()]);
        $provider = $this->provider($http, ttl: 300);

        $provider->publicKeyFor('key-1');
        $this->clock->now += 299;
        $provider->publicKeyFor('key-1');
        self::assertSame(1, $http->calls);

        $this->clock->now += 2;
        $provider->publicKeyFor('key-1');
        self::assertSame(2, $http->calls);
    }

    public function testTtlIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider($this->http([]), ttl: JwksKeyProvider::MAX_TTL_SECONDS + 1);
    }

    public function testUnknownKidRejected(): void
    {
        $this->expectException(InvalidAgentTokenException::class);

        $this->provider($this->http([$this->signer->jwk()]))->publicKeyFor('nope');
    }

    public function testKeyDeclaredForAnotherAlgorithmIsNotUsable(): void
    {
        $this->expectException(InvalidAgentTokenException::class);

        $this->provider($this->http([$this->signer->jwk(alg: 'RS512')]))->publicKeyFor('key-1');
    }

    public function testKeyMeantForEncryptionIsNotUsable(): void
    {
        $this->expectException(InvalidAgentTokenException::class);

        $this->provider($this->http([$this->signer->jwk(use: 'enc')]))->publicKeyFor('key-1');
    }

    public function testNonRsaKeyIsNotUsable(): void
    {
        $this->expectException(InvalidAgentTokenException::class);

        $this->provider($this->http([['kty' => 'oct', 'kid' => 'key-1', 'k' => 'c2VjcmV0']]))->publicKeyFor('key-1');
    }

    public function testUnreachableJwksFailsClosedAndIsNotCached(): void
    {
        $http = new FakeHttpClient(fails: true);
        $provider = $this->provider($http);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $provider->publicKeyFor('key-1');
                self::fail('no keys, no token');
            } catch (InvalidAgentTokenException) {
            }
        }

        self::assertSame(2, $http->calls);
    }

    public function testMalformedJwksDocumentFailsClosedAndIsNotCached(): void
    {
        $http = new FakeHttpClient('not json');
        $provider = $this->provider($http);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $provider->publicKeyFor('key-1');
                self::fail('no keys, no token');
            } catch (InvalidAgentTokenException) {
            }
        }

        self::assertSame(2, $http->calls);
    }

    public function testNonHttpsJwksUriRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JwksKeyProvider('http://idp.example.com/jwks', new FakeHttpClient(), new ArrayJwksCache($this->clock));
    }

    /**
     * @param list<array<string, string>> $keys
     */
    private function http(array $keys): FakeHttpClient
    {
        return new FakeHttpClient((string) json_encode(['keys' => $keys]));
    }

    private function provider(FakeHttpClient $http, int $ttl = 300): JwksKeyProvider
    {
        return new JwksKeyProvider(self::URI, $http, new ArrayJwksCache($this->clock), $ttl);
    }
}
