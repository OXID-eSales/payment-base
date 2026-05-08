<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp;

/**
 * Immutable value object representing an authenticated AI agent.
 */
readonly class AgentContext implements AgentContextInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $agentId,
        private string $token,
        private array $metadata = []
    ) {
    }

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }
}
