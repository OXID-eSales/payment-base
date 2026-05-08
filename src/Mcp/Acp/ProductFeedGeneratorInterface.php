<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp;

interface ProductFeedGeneratorInterface
{
    /**
     * Generate a product feed string in the implementing format.
     *
     * @param array<int, array<string, mixed>> $products Mapped product data
     * @return string Feed content (CSV, JSONL, etc.)
     */
    public function generate(array $products): string;

    /**
     * Get the MIME type of the generated feed.
     */
    public function getContentType(): string;

    /**
     * Get the file extension for the generated feed.
     */
    public function getFileExtension(): string;
}
