<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp\Tool;

use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentBase\Mcp\McpToolInterface;

class UpdateCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {
    }

    public function getName(): string
    {
        return 'update_checkout';
    }

    public function getDescription(): string
    {
        return 'Update a checkout session with shipping selection or other options';
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
                'selected_fulfillment_option_id' => [
                    'type' => 'string',
                    'description' => 'Selected shipping/delivery option ID',
                ],
            ],
            'required' => ['checkout_id'],
        ];
    }

    public function execute(array $arguments, AgentContextInterface $agentContext): array
    {
        $checkoutId = $arguments['checkout_id'];
        unset($arguments['checkout_id']);

        return $this->checkoutService->updateCheckout($checkoutId, $arguments, $agentContext);
    }
}
