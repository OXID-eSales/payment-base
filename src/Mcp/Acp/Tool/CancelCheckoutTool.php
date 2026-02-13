<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class CancelCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {
    }

    public function getName(): string
    {
        return 'cancel_checkout';
    }

    public function getDescription(): string
    {
        return 'Cancel an active checkout session';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkout_id' => [
                    'type' => 'string',
                    'description' => 'Checkout session ID',
                ],
            ],
            'required' => ['checkout_id'],
        ];
    }

    public function execute(array $arguments, AgentContextInterface $agentContext): array
    {
        return $this->checkoutService->cancelCheckout($arguments['checkout_id']);
    }
}
