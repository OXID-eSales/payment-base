<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp;

interface HostedCommerceServiceInterface
{
    /**
     * Upload/sync product catalog to the hosted commerce platform.
     *
     * @param string $feedContent Generated feed content (CSV, JSONL)
     * @param string $feedFormat Format identifier ('csv', 'jsonl')
     */
    public function syncCatalog(string $feedContent, string $feedFormat): CatalogSyncResult;

    /**
     * Upload partial inventory update.
     *
     * @param array<array{id: string, availability: string}> $inventoryUpdates
     */
    public function syncInventory(array $inventoryUpdates): CatalogSyncResult;

    /**
     * Update fulfillment status for a hosted order.
     *
     * @param string $orderId Hosted platform order ID
     * @param string $status New status ('shipped', 'fulfilled', 'canceled')
     * @param array<string, mixed> $metadata Tracking info, carrier, etc.
     */
    public function updateFulfillmentStatus(
        string $orderId,
        string $status,
        array $metadata = []
    ): bool;
}
