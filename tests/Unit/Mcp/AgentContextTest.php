<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp;

use OxidEsales\PaymentBase\Mcp\AgentContext;
use PHPUnit\Framework\TestCase;

class AgentContextTest extends TestCase
{
    public function testGetAgentId(): void
    {
        $ctx = new AgentContext('agent_abc', 'tok_123');
        $this->assertSame('agent_abc', $ctx->getAgentId());
    }

    public function testGetToken(): void
    {
        $ctx = new AgentContext('agent_abc', 'tok_123');
        $this->assertSame('tok_123', $ctx->getToken());
    }

    public function testGetMetadataReturnsDefault(): void
    {
        $ctx = new AgentContext('a', 't');
        $this->assertNull($ctx->getMetadata('missing'));
        $this->assertSame('fallback', $ctx->getMetadata('missing', 'fallback'));
    }

    public function testGetMetadataReturnsValue(): void
    {
        $ctx = new AgentContext('a', 't', ['role' => 'checkout']);
        $this->assertSame('checkout', $ctx->getMetadata('role'));
    }

    public function testGetAllMetadata(): void
    {
        $meta = ['key1' => 'val1', 'key2' => 42];
        $ctx = new AgentContext('a', 't', $meta);
        $this->assertSame($meta, $ctx->getAllMetadata());
    }

    public function testEmptyMetadataByDefault(): void
    {
        $ctx = new AgentContext('a', 't');
        $this->assertSame([], $ctx->getAllMetadata());
    }
}
