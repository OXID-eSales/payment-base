<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp;

/**
 * Authenticated AI agent context.
 */
interface AgentContextInterface
{
    public function getAgentId(): string;

    public function getToken(): string;

    public function getMetadata(string $key, mixed $default = null): mixed;

    /**
     * @return array<string, mixed>
     */
    public function getAllMetadata(): array;
}
