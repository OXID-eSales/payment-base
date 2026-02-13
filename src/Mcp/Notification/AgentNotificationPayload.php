<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

readonly class AgentNotificationPayload
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $eventType,
        private string $checkoutId,
        private string $status,
        private ?string $orderId = null,
        private ?string $permalinkUrl = null,
        private array $metadata = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'event_type' => $this->eventType,
            'checkout_session_id' => $this->checkoutId,
            'status' => $this->status,
            'timestamp' => time(),
        ];

        if ($this->orderId !== null) {
            $payload['order'] = [
                'id' => $this->orderId,
                'permalink_url' => $this->permalinkUrl,
            ];
        }

        if (!empty($this->metadata)) {
            $payload['metadata'] = $this->metadata;
        }

        return $payload;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }
}
