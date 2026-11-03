<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentBase\Mcp\Auth\InvalidAgentTokenException;
use OxidEsales\PaymentBase\Mcp\Auth\JwksKeyProvider;
use OxidEsales\PaymentBase\Mcp\Auth\JwtTokenValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/JwtTestKit.php';

/**
 * VR-24 H8 / S8: the signature is verified against the issuer's JWKS, the
 * algorithm is pinned, and issuer, audience and expiry are checked. Real RSA
 * keys, a fake HTTP client and a fake clock: no network.
 */
final class JwtTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://idp.example.com';
    private const AUDIENCE = 'https://shop.example.com/acp';
    private const JWKS_URI = 'https://idp.example.com/.well-known/jwks.json';

    private RsaSigner $signer;
    private FakeClock $clock;
    private FakeHttpClient $http;
    private JwtTokenValidator $validator;

    protected function setUp(): void
    {
        $this->signer = new RsaSigner('key-1');
        $this->clock = new FakeClock();
        $this->http = new FakeHttpClient((string) json_encode(['keys' => [$this->signer->jwk()]]));
        $this->validator = new JwtTokenValidator(
            new JwksKeyProvider(self::JWKS_URI, $this->http, new ArrayJwksCache($this->clock)),
            self::ISSUER,
            self::AUDIENCE,
            $this->clock,
        );
    }

    public function testValidTokenYieldsTheAgentContext(): void
    {
        $token = $this->signer->token($this->claims());

        $agent = $this->validator->authenticate($token);

        self::assertSame('agent-42', $agent->getAgentId());
        self::assertSame($token, $agent->getToken());
    }

    public function testAudienceMayBeAListContainingOurs(): void
    {
        $agent = $this->validator->authenticate(
            $this->signer->token($this->claims(['aud' => ['https://other', self::AUDIENCE]]))
        );

        self::assertSame('agent-42', $agent->getAgentId());
    }

    public function testForgedSignatureRejected(): void
    {
        $other = new RsaSigner('key-1');
        $forged = $other->token($this->claims());

        $this->assertRejected($forged, 'signature');
    }

    public function testTamperedPayloadRejected(): void
    {
        [$header, , $signature] = explode('.', $this->signer->token($this->claims()));
        $payload = RsaSigner::b64((string) json_encode($this->claims(['sub' => 'agent-evil'])));

        $this->assertRejected($header . '.' . $payload . '.' . $signature, 'signature');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongAlgorithms(): iterable
    {
        yield 'none' => ['none'];
        yield 'HS256' => ['HS256'];
        yield 'RS512' => ['RS512'];
        yield 'lower case' => ['rs256'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongAlgorithms')]
    public function testWrongAlgorithmRejectedBeforeAnyKeyIsFetched(string $alg): void
    {
        $token = $this->signer->token($this->claims(), ['alg' => $alg]);

        $this->assertRejected($token, 'algorithm');
        self::assertSame(0, $this->http->calls);
    }

    public function testUnsignedTokenRejected(): void
    {
        $unsigned = RsaSigner::b64('{"alg":"none"}') . '.' . RsaSigner::b64((string) json_encode($this->claims())) . '.';

        $this->assertRejected($unsigned, 'algorithm');
    }

    public function testUnknownKidRejected(): void
    {
        $this->assertRejected($this->signer->token($this->claims(), ['kid' => 'key-unknown']), 'key');
    }

    public function testMissingKidRejected(): void
    {
        $token = $this->signer->token($this->claims());
        [, $payload, $signature] = explode('.', $token);
        $header = RsaSigner::b64('{"alg":"RS256"}');

        $this->assertRejected($header . '.' . $payload . '.' . $signature, 'key');
    }

    public function testExpiredTokenRejected(): void
    {
        $this->assertRejected($this->signer->token($this->claims(['exp' => $this->clock->now - 1])), 'expired');
    }

    public function testTokenWithoutExpiryRejected(): void
    {
        $claims = $this->claims();
        unset($claims['exp']);

        $this->assertRejected($this->signer->token($claims), 'expiry');
    }

    public function testNotYetValidTokenRejected(): void
    {
        $this->assertRejected($this->signer->token($this->claims(['nbf' => $this->clock->now + 600])), 'not yet valid');
    }

    public function testWrongIssuerRejected(): void
    {
        $this->assertRejected($this->signer->token($this->claims(['iss' => 'https://evil.example'])), 'issuer');
    }

    public function testWrongAudienceRejected(): void
    {
        $this->assertRejected($this->signer->token($this->claims(['aud' => 'https://other'])), 'audience');
    }

    public function testMissingSubjectRejected(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);

        $this->assertRejected($this->signer->token($claims), 'subject');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'two segments' => ['a.b'];
        yield 'four segments' => ['a.b.c.d'];
        yield 'not base64 json' => ['!!!.???.***'];
        yield 'oversized' => [str_repeat('a', 9000) . '.b.c'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTokens')]
    public function testMalformedTokenRejected(string $token): void
    {
        $this->expectException(InvalidAgentTokenException::class);

        $this->validator->authenticate($token);
    }

    private function assertRejected(string $token, string $reasonFragment): void
    {
        try {
            $this->validator->authenticate($token);
            self::fail('the token must be rejected');
        } catch (InvalidAgentTokenException $e) {
            self::assertStringContainsString($reasonFragment, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'agent-42',
            'exp' => $this->clock->now + 300,
        ], $overrides);
    }
}
