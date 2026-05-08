<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp;

interface McpServerInterface
{
    /**
     * Handle a JSON-RPC 2.0 request string and return the response payload.
     *
     * Supported methods: initialize, tools/list, tools/call
     *
     * @param string $rawJsonRpc Raw JSON-RPC request body
     * @param AgentContextInterface $agentContext Authenticated agent
     * @return array<string, mixed> JSON-RPC 2.0 response
     */
    public function handleJsonRpc(string $rawJsonRpc, AgentContextInterface $agentContext): array;
}
