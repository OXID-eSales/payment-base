<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Notification;

interface AgentNotificationServiceInterface
{
    /**
     * Send a notification to the agent associated with a contract.
     */
    public function notify(string $contractId, AgentNotificationPayloadInterface $payload): AgentNotificationResult;
}
