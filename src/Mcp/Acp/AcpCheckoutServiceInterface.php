<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp;

use OxidEsales\PaymentBase\Mcp\AgentContextInterface;

interface AcpCheckoutServiceInterface
{
    /**
     * Create a new checkout session from ACP request data.
     *
     * @param array<string, mixed> $arguments ACP create_checkout input
     * @param AgentContextInterface $agentContext Authenticated agent
     * @return array<string, mixed> ACP checkout response
     */
    public function createCheckout(array $arguments, AgentContextInterface $agentContext): array;

    /**
     * Get checkout session status. A checkout the agent did not open is
     * answered like a missing one.
     *
     * @return array<string, mixed> ACP checkout response or error
     */
    public function getCheckout(string $checkoutId, AgentContextInterface $agentContext): array;

    /**
     * Update checkout session (shipping, options).
     *
     * @param array<string, mixed> $data Update fields
     * @return array<string, mixed> ACP checkout response or error
     */
    public function updateCheckout(string $checkoutId, array $data, AgentContextInterface $agentContext): array;

    /**
     * Complete checkout with delegated payment token.
     *
     * @param array<string, mixed> $paymentData Token, provider, billing address
     * @return array<string, mixed> ACP order response or error
     */
    public function completeCheckout(
        string $checkoutId,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array;

    /**
     * Cancel a checkout session. A checkout the agent did not open is
     * answered like a missing one.
     *
     * @return array<string, mixed> ACP checkout response (status: canceled)
     */
    public function cancelCheckout(string $checkoutId, AgentContextInterface $agentContext): array;
}
