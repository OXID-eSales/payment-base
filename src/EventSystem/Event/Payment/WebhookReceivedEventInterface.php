<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Payment;

interface WebhookReceivedEventInterface extends PaymentEventInterface
{
    public function getProvider(): string;

    public function getEventType(): string;

    public function getPayload(): array;

    public function getSignature(): string;
}
