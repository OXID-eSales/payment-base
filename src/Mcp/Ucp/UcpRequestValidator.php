<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Ucp;

class UcpRequestValidator
{
    /**
     * @param array<string, string> $headers
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateHeaders(array $headers): array
    {
        $errors = [];

        if (empty($headers['request-id'])) {
            $errors[] = 'Missing required header: Request-Id';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Extract agent profile URL from UCP-Agent header.
     * Format: UCP-Agent: profile="https://..."
     *
     * @param array<string, string> $headers
     */
    public function extractAgentProfile(array $headers): ?string
    {
        $ucpAgent = $headers['ucp-agent'] ?? '';
        if (preg_match('/profile="([^"]+)"/', $ucpAgent, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
