<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\AgentContext;

class McpAuthGuard implements McpAuthGuardInterface
{
    /**
     * @param string $expectedToken Injected via DI from provider module config
     */
    public function __construct(
        private readonly string $expectedToken
    ) {
    }

    public function authenticate(): AuthResult
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return AuthResult::failed('Missing Bearer token');
        }

        $token = substr($header, 7);

        if ($this->expectedToken === '' || !hash_equals($this->expectedToken, $token)) {
            return AuthResult::failed('Invalid token');
        }

        return AuthResult::success(new AgentContext(
            agentId: $this->deriveAgentId($token),
            token: $token
        ));
    }

    /**
     * Derive a stable agent identifier from the token.
     * Uses first 8 chars of SHA-256 hash — not sensitive, just an ID.
     */
    private function deriveAgentId(string $token): string
    {
        return 'agent_' . substr(hash('sha256', $token), 0, 8);
    }
}
