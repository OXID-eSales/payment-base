<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;

class AgentCallbackRegistry implements AgentCallbackRegistryInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository
    ) {
    }

    public function register(string $contractId, string $agentId, string $callbackUrl): void
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return;
        }

        $contract->setMetadata('agent_callback_url', $callbackUrl);
        $contract->setMetadata('agent_id', $agentId);
        $this->contractRepository->save($contract);
    }

    public function getCallbackUrl(string $contractId): ?string
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return null;
        }

        $value = $contract->getMetadata('agent_callback_url');
        return is_string($value) ? $value : null;
    }

    public function getAgentId(string $contractId): ?string
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return null;
        }

        $value = $contract->getMetadata('agent_id');
        return is_string($value) ? $value : null;
    }
}
