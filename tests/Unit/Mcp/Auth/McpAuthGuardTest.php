<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuard;
use PHPUnit\Framework\TestCase;

class McpAuthGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testMissingBearerTokenFailsAuth(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $guard = new McpAuthGuard('expected_token');
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Missing Bearer token', $result->getErrorMessage());
    }

    public function testInvalidTokenFailsAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong_token';

        $guard = new McpAuthGuard('correct_token');
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Invalid token', $result->getErrorMessage());
    }

    public function testEmptyExpectedTokenFailsAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer any_token';

        $guard = new McpAuthGuard('');
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Invalid token', $result->getErrorMessage());
    }

    public function testValidTokenSucceeds(): void
    {
        $token = 'sk_test_valid_token_123';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $guard = new McpAuthGuard($token);
        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());
        $agentCtx = $result->getAgentContext();
        $this->assertSame($token, $agentCtx->getToken());
        $this->assertStringStartsWith('agent_', $agentCtx->getAgentId());
    }

    public function testAgentIdIsDeterministic(): void
    {
        $token = 'deterministic_test_token';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $guard = new McpAuthGuard($token);
        $result1 = $guard->authenticate();
        $result2 = $guard->authenticate();

        $this->assertSame(
            $result1->getAgentContext()->getAgentId(),
            $result2->getAgentContext()->getAgentId()
        );
    }

    public function testNonBearerSchemeFailsAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $guard = new McpAuthGuard('some_token');
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Missing Bearer token', $result->getErrorMessage());
    }
}
