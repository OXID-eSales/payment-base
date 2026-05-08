<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Http;

readonly class HttpClientResponse
{
    public function __construct(
        private int $statusCode,
        private string $body,
        private ?string $error = null
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccessful(): bool
    {
        return $this->error === null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public static function failed(string $error): self
    {
        return new self(0, '', $error);
    }
}
