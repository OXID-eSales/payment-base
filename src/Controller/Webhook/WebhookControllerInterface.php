<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Controller\Webhook;

interface WebhookControllerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(string $payload, string $signature): array;
}
