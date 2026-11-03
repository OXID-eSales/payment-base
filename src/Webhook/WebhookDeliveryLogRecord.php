<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

/**
 * The one shape of a webhook file-log line (VR-18 L2): which delivery, which object and contract
 * it touched, what was done and why - ids and codes, never payload content. A "skipped" line
 * without a reason, or a line without the ids, is what made the pay1 webhook logs undiagnosable.
 *
 * Providers hand it to their file logger as `$logger->log('webhook', $record->toContext())`.
 */
final readonly class WebhookDeliveryLogRecord
{
    /**
     * @param ?string $reason a machine-readable code (`contract_not_found`), never free text
     */
    public function __construct(
        public string $provider,
        public ?string $eventId,
        public ?string $eventType,
        public ?string $objectId,
        public ?string $contractId,
        public string $action,
        public ?string $reason,
        public int $httpCode,
        public int $durationMs,
    ) {
    }

    public static function fromResult(
        string $provider,
        ?string $eventId,
        ?string $eventType,
        ?string $objectId,
        ?string $contractId,
        WebhookResult $result,
        int $httpCode,
        int $durationMs,
    ): self {
        return new self(
            $provider,
            $eventId,
            $eventType,
            $objectId,
            $contractId,
            $result->action,
            $result->error,
            $httpCode,
            $durationMs,
        );
    }

    /**
     * @return array{
     *     provider: string, eventId: ?string, eventType: ?string, objectId: ?string, contractId: ?string,
     *     action: string, reason: ?string, httpCode: int, durationMs: int
     * }
     */
    public function toContext(): array
    {
        return [
            'provider' => $this->provider,
            'eventId' => $this->eventId,
            'eventType' => $this->eventType,
            'objectId' => $this->objectId,
            'contractId' => $this->contractId,
            'action' => $this->action,
            'reason' => $this->reason,
            'httpCode' => $this->httpCode,
            'durationMs' => $this->durationMs,
        ];
    }
}
