<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

class JwtTokenValidator implements TokenValidatorInterface
{
    /**
     * @param string $issuer Expected JWT issuer (iss claim)
     * @param string $audience Expected JWT audience (aud claim)
     */
    public function __construct(
        private readonly string $issuer,
        private readonly string $audience
    ) {
    }

    public function validate(string $token): TokenValidationResult
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return TokenValidationResult::invalid('Not a valid JWT format');
            }

            $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true
            );

            if (!is_array($payload)) {
                return TokenValidationResult::invalid('Invalid JWT payload');
            }

            if (($payload['iss'] ?? '') !== $this->issuer) {
                return TokenValidationResult::invalid('Invalid issuer');
            }

            $aud = $payload['aud'] ?? '';
            $audienceMatch = is_array($aud)
                ? in_array($this->audience, $aud, true)
                : $aud === $this->audience;

            if (!$audienceMatch) {
                return TokenValidationResult::invalid('Invalid audience');
            }

            $exp = (int) ($payload['exp'] ?? 0);
            if ($exp < time()) {
                return TokenValidationResult::invalid('Token expired');
            }

            return TokenValidationResult::valid(
                (string) ($payload['sub'] ?? 'unknown'),
                (string) ($payload['client_id'] ?? $payload['azp'] ?? ''),
                isset($payload['scope']) && is_string($payload['scope'])
                    ? explode(' ', $payload['scope'])
                    : [],
                $exp
            );
        } catch (\Throwable $e) {
            return TokenValidationResult::invalid('JWT validation error: ' . $e->getMessage());
        }
    }
}
