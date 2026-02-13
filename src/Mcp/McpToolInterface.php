<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp;

interface McpToolInterface
{
    /**
     * Unique tool name (e.g., 'create_checkout').
     */
    public function getName(): string;

    /**
     * Human-readable description shown to AI agents.
     */
    public function getDescription(): string;

    /**
     * JSON Schema defining the tool's input parameters.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with validated arguments.
     *
     * @param array<string, mixed> $arguments Validated input
     * @param AgentContextInterface $agentContext Authenticated agent
     * @return array<string, mixed> Tool result (MCP content format)
     */
    public function execute(array $arguments, AgentContextInterface $agentContext): array;
}
