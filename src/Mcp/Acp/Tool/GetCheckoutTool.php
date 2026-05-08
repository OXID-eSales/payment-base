<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp\Tool;

use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentBase\Mcp\McpToolInterface;

class GetCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {
    }

    public function getName(): string
    {
        return 'get_checkout';
    }

    public function getDescription(): string
    {
        return 'Retrieve the current status of a checkout session';
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
        return $this->checkoutService->getCheckout($arguments['checkout_id']);
    }
}
