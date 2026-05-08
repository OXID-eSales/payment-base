<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp\Tool;

use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentBase\Mcp\McpToolInterface;

class ListProductsTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpProductServiceInterface $productService
    ) {
    }

    public function getName(): string
    {
        return 'list_products';
    }

    public function getDescription(): string
    {
        return 'Search and list available products in the shop catalog';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Search query for product title or description',
                ],
                'category_id' => [
                    'type' => 'string',
                    'description' => 'Filter by category ID',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset',
                    'default' => 0,
                    'minimum' => 0,
                ],
            ],
        ];
    }

    public function execute(array $arguments, AgentContextInterface $agentContext): array
    {
        return $this->productService->listProducts($arguments);
    }
}
