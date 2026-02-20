<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

class WebhookLog
{
    private readonly string $id;
    private ?string $eventType = null;
    private ?string $contractId = null;
    private ?string $error = null;
    private ?string $provider = null;
    /** @var array<string, mixed>|null */
    private ?array $payload = null;
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(
        private readonly string $eventId,
        private readonly \DateTimeImmutable $receivedAt,
        private readonly string $status,
        ?string $id = null
    ) {
        // Allow ID to be provided (for hydration from DB) or auto-generate (for new instances)
        $this->id = $id ?? 'webhook_log_' . bin2hex(random_bytes(16));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function setContractId(string $contractId): void
    {
        $this->contractId = $contractId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(\DateTimeImmutable $processedAt): void
    {
        $this->processedAt = $processedAt;
    }
}
