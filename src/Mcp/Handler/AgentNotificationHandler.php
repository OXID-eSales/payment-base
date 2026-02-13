<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationPayload;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationServiceInterface;

class AgentNotificationHandler implements HandlerInterface
{
    public function __construct(
        private readonly AgentNotificationServiceInterface $notificationService
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractCommittedEvent::class;
    }

    public function handle(object $event): void
    {
        $contract = $this->extractContract($event);
        if ($contract === null) {
            return;
        }

        $agentId = $contract->getMetadata('acp_agent_id');
        if ($agentId === null) {
            return;
        }

        $contractId = $contract->getId();
        if ($contractId === null) {
            return;
        }

        $payload = $this->buildPayload($event, $contract);
        if ($payload === null) {
            return;
        }

        $this->notificationService->notify($contractId, $payload);
    }

    private function extractContract(object $event): ?PaymentContractInterface
    {
        if (method_exists($event, 'getContract')) {
            return $event->getContract();
        }
        return null;
    }

    private function buildPayload(object $event, PaymentContractInterface $contract): ?AgentNotificationPayload
    {
        $contractId = $contract->getId() ?? '';
        $orderId = $contract->getOrderId();

        return match (true) {
            $event instanceof ContractCommittedEvent => new AgentNotificationPayload(
                'order.created',
                $contractId,
                'created',
                $orderId
            ),
            $event instanceof ContractFulfilledEvent => new AgentNotificationPayload(
                'order.fulfilled',
                $contractId,
                'fulfilled',
                $orderId
            ),
            $event instanceof ContractCancelledEvent => new AgentNotificationPayload(
                'order.canceled',
                $contractId,
                'canceled'
            ),
            $event instanceof ContractFailedEvent => new AgentNotificationPayload(
                'order.failed',
                $contractId,
                'canceled'
            ),
            default => null,
        };
    }
}
