<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

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

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->checkoutService->getCheckout($arguments['checkout_id']);
    }
}
