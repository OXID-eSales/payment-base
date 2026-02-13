<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

readonly class CatalogSyncResult
{
    /**
     * @param array<string> $errorMessages
     */
    private function __construct(
        private bool $successful,
        private int $productsProcessed,
        private int $productsCreated,
        private int $productsUpdated,
        private int $errors,
        private array $errorMessages
    ) {
    }

    public static function success(int $processed, int $created, int $updated): self
    {
        return new self(true, $processed, $created, $updated, 0, []);
    }

    /**
     * @param array<string> $errorMessages
     */
    public static function partial(
        int $processed,
        int $created,
        int $updated,
        int $errors,
        array $errorMessages
    ): self {
        return new self($errors === 0, $processed, $created, $updated, $errors, $errorMessages);
    }

    public static function failed(string $error): self
    {
        return new self(false, 0, 0, 0, 1, [$error]);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getProductsProcessed(): int
    {
        return $this->productsProcessed;
    }

    public function getProductsCreated(): int
    {
        return $this->productsCreated;
    }

    public function getProductsUpdated(): int
    {
        return $this->productsUpdated;
    }

    public function getErrors(): int
    {
        return $this->errors;
    }

    /**
     * @return array<string>
     */
    public function getErrorMessages(): array
    {
        return $this->errorMessages;
    }
}
