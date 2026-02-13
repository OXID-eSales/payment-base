<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

readonly class TokenValidationResult
{
    /**
     * @param array<string> $scopes
     */
    private function __construct(
        private bool $valid,
        private ?string $subject,
        private ?string $clientId,
        private array $scopes,
        private ?int $expiresAt,
        private ?string $errorMessage
    ) {
    }

    /**
     * @param array<string> $scopes
     */
    public static function valid(string $subject, string $clientId, array $scopes, int $expiresAt): self
    {
        return new self(true, $subject, $clientId, $scopes, $expiresAt, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, null, null, [], null, $reason);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getExpiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
