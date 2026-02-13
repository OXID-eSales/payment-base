<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

interface AgentCallbackRegistryInterface
{
    /**
     * Register a callback URL for an agent on a specific contract.
     */
    public function register(string $contractId, string $agentId, string $callbackUrl): void;

    /**
     * Get the callback URL for a contract's agent.
     */
    public function getCallbackUrl(string $contractId): ?string;

    /**
     * Get the agent ID for a contract.
     */
    public function getAgentId(string $contractId): ?string;
}
