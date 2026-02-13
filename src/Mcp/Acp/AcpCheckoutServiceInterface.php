<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;

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
     * Get checkout session status.
     *
     * @return array<string, mixed> ACP checkout response or error
     */
    public function getCheckout(string $checkoutId): array;

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
     * Cancel a checkout session.
     *
     * @return array<string, mixed> ACP checkout response (status: canceled)
     */
    public function cancelCheckout(string $checkoutId): array;
}
