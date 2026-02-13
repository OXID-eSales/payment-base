<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

readonly class AgentNotificationResult
{
    private function __construct(
        private bool $delivered,
        private int $httpStatusCode,
        private ?string $errorMessage
    ) {
    }

    public static function success(int $httpStatusCode): self
    {
        return new self(true, $httpStatusCode, null);
    }

    public static function failed(int $httpStatusCode, string $error): self
    {
        return new self(false, $httpStatusCode, $error);
    }

    public static function noCallback(): self
    {
        return new self(false, 0, 'No callback URL registered');
    }

    public function isDelivered(): bool
    {
        return $this->delivered;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
