<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

interface ProductFieldMapperInterface
{
    /**
     * Map a shop-internal product representation to ACP feed fields.
     *
     * @param mixed $product Shop-specific product model
     * @return array<string, mixed> ACP-compatible field map
     */
    public function mapProduct(mixed $product): array;

    /**
     * Get the ordered list of field names for header generation.
     *
     * @return array<string>
     */
    public function getFieldNames(): array;
}
