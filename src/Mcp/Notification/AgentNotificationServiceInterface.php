<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

interface AgentNotificationServiceInterface
{
    /**
     * Send a notification to the agent associated with a contract.
     */
    public function notify(string $contractId, AgentNotificationPayload $payload): AgentNotificationResult;
}
