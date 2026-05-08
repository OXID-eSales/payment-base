<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

use OxidEsales\PaymentBase\Mcp\AgentContext;

class OAuthMcpAuthGuard implements McpAuthGuardInterface
{
    public function __construct(
        private readonly TokenValidatorInterface $tokenValidator,
        private readonly string $staticToken = ''
    ) {
    }

    public function authenticate(): AuthResult
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return AuthResult::failed('Missing Bearer token');
        }

        $token = substr($header, 7);

        // Try static token first (backward compat with Sprint 47)
        if ($this->staticToken !== '' && hash_equals($this->staticToken, $token)) {
            return AuthResult::success(new AgentContext(
                agentId: 'agent_' . substr(hash('sha256', $token), 0, 8),
                token: $token,
                metadata: ['auth_method' => 'bearer_static']
            ));
        }

        // Try OAuth token validation
        $validationResult = $this->tokenValidator->validate($token);
        if (!$validationResult->isValid()) {
            return AuthResult::failed($validationResult->getErrorMessage() ?? 'Invalid token');
        }

        return AuthResult::success(new AgentContext(
            agentId: $validationResult->getSubject() ?? 'unknown',
            token: $token,
            metadata: [
                'auth_method' => 'oauth',
                'client_id' => $validationResult->getClientId(),
                'scopes' => $validationResult->getScopes(),
                'expires_at' => $validationResult->getExpiresAt(),
            ]
        ));
    }
}
