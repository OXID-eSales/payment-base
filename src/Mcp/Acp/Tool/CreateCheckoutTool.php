<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class CreateCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {
    }

    public function getName(): string
    {
        return 'create_checkout';
    }

    public function getDescription(): string
    {
        return 'Create an ACP checkout session for the given items and buyer information';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'Products to purchase',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Product/article ID'],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'required' => ['id', 'quantity'],
                    ],
                ],
                'buyer' => [
                    'type' => 'object',
                    'description' => 'Buyer information',
                    'properties' => [
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'phone_number' => ['type' => 'string'],
                    ],
                    'required' => ['email'],
                ],
                'fulfillment_address' => [
                    'type' => 'object',
                    'description' => 'Shipping address',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'line_one' => ['type' => 'string'],
                        'line_two' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'country' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2'],
                        'postal_code' => ['type' => 'string'],
                    ],
                    'required' => ['line_one', 'city', 'country', 'postal_code'],
                ],
                'currency' => [
                    'type' => 'string',
                    'description' => 'ISO 4217 currency code',
                    'default' => 'EUR',
                ],
            ],
            'required' => ['items', 'buyer'],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->checkoutService->createCheckout($arguments, $agentContext);
    }
}
