<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Mcp\Auth;

use LogicException;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Auth\AuthResult;
use PHPUnit\Framework\TestCase;

class AuthResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $ctx = new AgentContext('agent_1', 'tok');
        $result = AuthResult::success($ctx);

        $this->assertTrue($result->isAuthenticated());
        $this->assertSame($ctx, $result->getAgentContext());
        $this->assertNull($result->getErrorMessage());
    }

    public function testFailedFactory(): void
    {
        $result = AuthResult::failed('Bad token');

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Bad token', $result->getErrorMessage());
    }

    public function testGetAgentContextThrowsOnFailed(): void
    {
        $result = AuthResult::failed('No access');

        $this->expectException(LogicException::class);
        $result->getAgentContext();
    }
}
