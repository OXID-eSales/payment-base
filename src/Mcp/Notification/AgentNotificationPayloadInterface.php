<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Notification;

interface AgentNotificationPayloadInterface
{
    public function toJson(): string;

    public function getEventType(): string;
}
