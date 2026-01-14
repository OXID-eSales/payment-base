<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

interface WebhookProcessorInterface
{
    /**
     * @param array<string, mixed> $webhookData
     */
    public function process(array $webhookData): void;
}
