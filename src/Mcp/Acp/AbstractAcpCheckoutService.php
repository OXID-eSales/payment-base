<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;

abstract class AbstractAcpCheckoutService implements AcpCheckoutServiceInterface
{
    /**
     * Sprint 15 / S7 (GRAPH-QL): the four headless collaborators are optional
     * so a provider service wired before this sprint keeps working; the
     * default createCheckout() and commitPaid() need them.
     */
    public function __construct(
        protected readonly ContractServiceInterface $contractService,
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly AcpResponseFormatterInterface $formatter,
        protected readonly ?ContractOpeningServiceInterface $contractOpening = null,
        protected readonly ?UserBasketFactoryInterface $userBaskets = null,
        protected readonly ?GuestUserResolverInterface $buyers = null,
        protected readonly ?ContractCommitServiceInterface $contractCommit = null
    ) {
    }

    /**
     * The payment id this provider's checkouts are paid with
     * (e.g. `oe_payments_stripe_wallet`).
     */
    abstract protected function paymentId(): string;

    /**
     * The provider as it names itself in contracts and contexts
     * (`stripe`, `mollie`, `paypal`).
     */
    abstract protected function providerName(): string;

    /**
     * Sprint 15 / S7 — `create_checkout` on the headless path: the buyer
     * becomes a shop user (existing account or guest), the items a persisted
     * user basket paying with this provider, and the contract is opened
     * through the same chain as every other checkout (early order, PENDING).
     * No PSP session: the agent pays with a delegated token in
     * complete_checkout. Override only when the provider needs more.
     */
    public function createCheckout(array $arguments, AgentContextInterface $agentContext): array
    {
        $items = $arguments['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return $this->formatter->validationError('At least one item is required', 'items');
        }

        /** @var array<string, mixed> $buyer */
        $buyer = is_array($arguments['buyer'] ?? null) ? $arguments['buyer'] : [];
        if (!is_string($buyer['email'] ?? null) || trim($buyer['email']) === '') {
            return $this->formatter->validationError('A buyer e-mail is required', 'buyer.email');
        }

        if ($this->contractOpening === null || $this->userBaskets === null || $this->buyers === null) {
            return $this->formatter->validationError(
                'Headless checkout is not wired for this provider (contract opening, user baskets, buyers)'
            );
        }

        /** @var array<string, mixed>|null $address */
        $address = is_array($arguments['fulfillment_address'] ?? null) ? $arguments['fulfillment_address'] : null;
        /** @var list<array{id?: string, quantity?: int|float}> $lines */
        $lines = array_values($items);

        try {
            $userId = $this->buyers->resolve($buyer, $address);
            $basketId = $this->userBaskets->create($userId, $lines, $this->paymentId());
            $contract = $this->contractOpening->open($userId, $basketId, $this->paymentId(), 'acp');
        } catch (HeadlessCheckoutException $e) {
            return $this->formatter->validationError($e->getMessage(), 'items');
        } catch (\InvalidArgumentException $e) {
            return $this->formatter->validationError($e->getMessage());
        }

        $contract->setMetadata('acp_agent_id', $agentContext->getAgentId());
        $this->contractRepository->save($contract);

        return $this->formatter->formatCheckout($contract);
    }

    /**
     * Sprint 15 / S7 — for completePayment(): once the provider has charged
     * the delegated token, this commits the contract through the same
     * service the webhooks and the headless return use.
     */
    protected function commitPaid(
        PaymentContractInterface $contract,
        string $authorizationId,
        string $providerOrderId,
        float $amount,
        string $currency,
        bool $requiresCapture = false
    ): CommitOutcome {
        if ($this->contractCommit === null) {
            throw new \LogicException('commitPaid() needs the ContractCommitServiceInterface collaborator');
        }

        return $this->contractCommit->commit(new PaymentConfirmation(
            contractId: (string) $contract->getId(),
            providerName: $this->providerName(),
            authorizationId: $authorizationId,
            providerOrderId: $providerOrderId,
            amount: $amount,
            currency: $currency,
            requiresCapture: $requiresCapture,
            source: 'acp',
        ));
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
