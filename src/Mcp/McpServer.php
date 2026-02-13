<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp;

class McpServer implements McpServerInterface
{
    private const PROTOCOL_VERSION = '2025-06-18';

    /** @var array<string, McpToolInterface> */
    private array $tools;

    private string $serverName;
    private string $serverVersion;

    /**
     * @param iterable<McpToolInterface> $taggedTools Collected via !tagged_iterator
     * @param string $serverName Configurable per provider module
     * @param string $serverVersion Configurable per provider module
     */
    public function __construct(
        iterable $taggedTools,
        string $serverName = 'oxid-payment-mcp',
        string $serverVersion = '1.0.0'
    ) {
        $this->serverName = $serverName;
        $this->serverVersion = $serverVersion;
        $this->tools = [];
        foreach ($taggedTools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    public function handleJsonRpc(string $rawJsonRpc, AgentContextInterface $agentContext): array
    {
        $request = $this->parseRequest($rawJsonRpc);
        if ($request === null) {
            return $this->errorResponse(null, -32700, 'Parse error');
        }

        $method = is_string($request['method'] ?? null) ? $request['method'] : '';
        $rawId = $request['id'] ?? null;
        $id = is_int($rawId) || is_string($rawId) ? $rawId : null;
        /** @var array<string, mixed> $params */
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        return match ($method) {
            'initialize' => $this->handleInitialize($id, $params),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params, $agentContext),
            default => $this->errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseRequest(string $rawJsonRpc): ?array
    {
        try {
            $decoded = json_decode($rawJsonRpc, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }
            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleInitialize(int|string|null $id, array $params): array
    {
        return $this->successResponse($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => true],
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(int|string|null $id): array
    {
        $toolList = [];
        foreach ($this->tools as $tool) {
            $toolList[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return $this->successResponse($id, ['tools' => $toolList]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(
        int|string|null $id,
        array $params,
        AgentContextInterface $agentContext
    ): array {
        $toolName = is_string($params['name'] ?? null) ? $params['name'] : '';
        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!isset($this->tools[$toolName])) {
            return $this->errorResponse($id, -32602, "Unknown tool: {$toolName}");
        }

        try {
            $result = $this->tools[$toolName]->execute($arguments, $agentContext);
            return $this->successResponse($id, [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)],
                ],
            ]);
        } catch (\Throwable) {
            return $this->errorResponse($id, -32000, 'Tool execution failed');
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function successResponse(int|string|null $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
