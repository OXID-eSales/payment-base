<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

interface WebhookIdempotencyCheckerInterface
{
    public function isProcessed(string $eventId): bool;

    public function markAsProcessed(string $eventId): void;
}
