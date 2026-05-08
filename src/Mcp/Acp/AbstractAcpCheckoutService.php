<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;

abstract class AbstractAcpCheckoutService implements AcpCheckoutServiceInterface
{
    public function __construct(
        protected readonly ContractServiceInterface $contractService,
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly AcpResponseFormatterInterface $formatter
    ) {
    }

    public function getCheckout(string $checkoutId): array
    {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        return $this->formatter->formatCheckout($contract);
    }

    public function updateCheckout(string $checkoutId, array $data, AgentContextInterface $agentContext): array
    {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        foreach ($data as $key => $value) {
            $contract->setMetadata('acp_' . $key, $value);
        }

        if (isset($data['selected_fulfillment_option_id'])) {
            $contract->setMetadata(
                'fulfillment_option',
                $data['selected_fulfillment_option_id']
            );
        }

        $this->contractRepository->save($contract);

        return $this->formatter->formatCheckout($contract);
    }

    public function cancelCheckout(string $checkoutId): array
    {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        if ($contract->getState()->isTerminal()) {
            return $this->formatter->validationError(
                'Checkout is already in a terminal state',
                'checkout_id'
            );
        }

        $contract->cancel();
        $this->contractRepository->save($contract);

        return $this->formatter->formatCheckout($contract);
    }

    /**
     * Provider-specific payment confirmation.
     *
     * Called by completeCheckout() after contract validation.
     * Stripe implements this with SPT -> PaymentIntent.
     * Other providers implement with their own token flow.
     *
     * @param PaymentContractInterface $contract Validated, non-terminal contract
     * @param array<string, mixed> $paymentData Token, provider, billing address
     * @param AgentContextInterface $agentContext Authenticated agent
     * @return array<string, mixed> ACP order response or error
     */
    abstract protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array;

    public function completeCheckout(
        string $checkoutId,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        if ($contract->getState()->isTerminal()) {
            return $this->formatter->validationError(
                'Checkout is already in a terminal state',
                'checkout_id'
            );
        }

        $token = $paymentData['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return $this->formatter->validationError(
                'Payment token is required',
                'payment_data.token'
            );
        }

        $contract->setMetadata('acp_agent_id', $agentContext->getAgentId());
        $contract->setMetadata('acp_completed_at', time());
        $this->contractRepository->save($contract);

        return $this->completePayment($contract, $paymentData, $agentContext);
    }
}
