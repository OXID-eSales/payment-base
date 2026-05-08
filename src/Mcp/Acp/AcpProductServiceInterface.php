<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp;

interface AcpProductServiceInterface
{
    /**
     * List available products in ACP format.
     *
     * @param array<string, mixed> $filters Optional filters (category, search, limit, offset)
     * @return array<string, mixed> ACP product list
     */
    public function listProducts(array $filters = []): array;

    /**
     * Get a single product by ID in ACP format.
     *
     * @return array<string, mixed>|null ACP product or null if not found
     */
    public function getProduct(string $productId): ?array;
}
