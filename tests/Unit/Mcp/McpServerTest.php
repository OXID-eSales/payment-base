<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Mcp;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\McpServer;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;
use PHPUnit\Framework\TestCase;

class McpServerTest extends TestCase
{
    private AgentContext $agentContext;

    protected function setUp(): void
    {
        $this->agentContext = new AgentContext('agent_1', 'token');
    }

    public function testInitializeReturnsProtocolVersion(): void
    {
        $server = new McpServer([], 'test-server', '1.0.0');

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $response = $server->handleJsonRpc($request, $this->agentContext);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertSame('test-server', $response['result']['serverInfo']['name']);
        $this->assertSame('1.0.0', $response['result']['serverInfo']['version']);
        $this->assertArrayHasKey('capabilities', $response['result']);
        $this->assertTrue($response['result']['capabilities']['tools']['listChanged']);
    }

    public function testToolsListReturnsRegisteredTools(): void
    {
        $mockTool = $this->createMock(McpToolInterface::class);
        $mockTool->method('getName')->willReturn('test_tool');
        $mockTool->method('getDescription')->willReturn('A test tool');
        $mockTool->method('getInputSchema')->willReturn([
            'type' => 'object',
            'properties' => ['foo' => ['type' => 'string']],
        ]);

        $server = new McpServer([$mockTool], 'test-server', '1.0.0');

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);

        $response = $server->handleJsonRpc($request, $this->agentContext);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(2, $response['id']);
        $this->assertCount(1, $response['result']['tools']);
        $this->assertSame('test_tool', $response['result']['tools'][0]['name']);
        $this->assertSame('A test tool', $response['result']['tools'][0]['description']);
    }

    public function testToolsCallExecutesTool(): void
    {
        $mockTool = $this->createMock(McpToolInterface::class);
        $mockTool->method('getName')->willReturn('my_tool');
        $mockTool->expects($this->once())
            ->method('execute')
            ->with(['key' => 'value'], $this->agentContext)
            ->willReturn(['status' => 'ok']);

        $server = new McpServer([$mockTool], 'test-server', '1.0.0');

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'my_tool',
                'arguments' => ['key' => 'value'],
            ],
        ]);

        $response = $server->handleJsonRpc($request, $this->agentContext);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(3, $response['id']);
        $this->assertArrayHasKey('content', $response['result']);
        $this->assertCount(1, $response['result']['content']);
        $this->assertSame('text', $response['result']['content'][0]['type']);

        $decodedText = json_decode($response['result']['content'][0]['text'], true);
        $this->assertSame('ok', $decodedText['status']);
    }

    public function testToolsCallReturnsErrorForUnknownTool(): void
    {
        $server = new McpServer([], 'test-server', '1.0.0');

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'nonexistent_tool',
                'arguments' => [],
            ],
        ]);

        $response = $server->handleJsonRpc($request, $this->agentContext);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(4, $response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('nonexistent_tool', $response['error']['message']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $server = new McpServer([], 'test-server', '1.0.0');

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'foo/bar',
        ]);

        $response = $server->handleJsonRpc($request, $this->agentContext);

        $this->assertSame(-32601, $response['error']['code']);
        $this->assertStringContainsString('foo/bar', $response['error']['message']);
    }

    public function testMalformedJsonReturnsParseError(): void
    {
        $server = new McpServer([], 'test-server', '1.0.0');

        $response = $server->handleJsonRpc('{invalid json!!!', $this->agentContext);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertNull($response['id']);
        $this->assertSame(-32700, $response['error']['code']);
        $this->assertSame('Parse error', $response['error']['message']);
    }

    public function testToolExceptionReturnsServerError(): void
    {
        $mockTool = $this->createMock(McpToolInterface::class);
        $mockTool->method('getName')->willReturn('bad_tool');
        $mockTool->method('execute')
            ->willThrowException(new \RuntimeException('Tool crashed'));

        $server = new McpServer([$mockTool], 'test-server', '1.0.0');

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'bad_tool', 'arguments' => []],
        ]);

        $response = $server->handleJsonRpc($request, $this->agentContext);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertSame('Tool crashed', $response['error']['message']);
    }
}
