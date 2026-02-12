<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class CompleteCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {
    }

    public function getName(): string
    {
        return 'complete_checkout';
    }

    public function getDescription(): string
    {
        return 'Complete checkout and process payment using a delegated payment token';
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
                'payment_data' => [
                    'type' => 'object',
                    'description' => 'Delegated payment credentials',
                    'properties' => [
                        'token' => [
                            'type' => 'string',
                            'description' => 'Delegated payment token from payment provider',
                        ],
                        'provider' => [
                            'type' => 'string',
                            'description' => 'Payment provider name',
                        ],
                        'billing_address' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'line_one' => ['type' => 'string'],
                                'line_two' => ['type' => 'string'],
                                'city' => ['type' => 'string'],
                                'state' => ['type' => 'string'],
                                'country' => ['type' => 'string'],
                                'postal_code' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'required' => ['token', 'provider'],
                ],
            ],
            'required' => ['checkout_id', 'payment_data'],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->checkoutService->completeCheckout(
            $arguments['checkout_id'],
            $arguments['payment_data'],
            $agentContext
        );
    }
}
