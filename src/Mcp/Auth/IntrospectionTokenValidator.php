<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;

class IntrospectionTokenValidator implements TokenValidatorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $introspectionEndpoint,
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {
    }

    public function validate(string $token): TokenValidationResult
    {
        $response = $this->httpClient->post(
            $this->introspectionEndpoint,
            http_build_query(['token' => $token]),
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            5
        );

        if (!$response->isSuccessful()) {
            return TokenValidationResult::invalid(
                'Introspection request failed: '
                . ($response->getError() ?? 'HTTP ' . $response->getStatusCode())
            );
        }

        $data = json_decode($response->getBody(), true);
        if (!is_array($data) || !($data['active'] ?? false)) {
            return TokenValidationResult::invalid('Token is not active');
        }

        return TokenValidationResult::valid(
            (string) ($data['sub'] ?? 'unknown'),
            (string) ($data['client_id'] ?? ''),
            isset($data['scope']) && is_string($data['scope']) ? explode(' ', $data['scope']) : [],
            (int) ($data['exp'] ?? 0)
        );
    }
}
