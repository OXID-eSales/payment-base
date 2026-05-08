<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

interface McpAuthGuardInterface
{
    /**
     * Authenticate an incoming MCP request.
     *
     * @return AuthResult Contains success/failure and AgentContext on success
     */
    public function authenticate(): AuthResult;
}
