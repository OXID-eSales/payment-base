<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

interface WebhookSignatureVerifierInterface
{
    public function verify(string $payload, string $signature): bool;

    /**
     * @return array<string, mixed>
     */
    public function parseEvent(string $payload, string $signature): array;
}
