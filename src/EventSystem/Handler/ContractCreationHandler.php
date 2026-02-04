<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use InvalidArgumentException;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;

/**
 * Abstract base handler for contract creation using Template Method pattern.
 *
 * Sprint 1: Refactored to use Template Method pattern.
 * This allows provider modules (Stripe, PayPal, etc.) to extend this handler
 * with provider-specific logic while reusing common validation and contract
 * creation code.
 *
 * Template Method Pattern:
 * - final handle(): Template with common validation and contract creation
 * - afterContractCreated(): Hook for provider-specific post-creation logic
 * - dispatchContractEvent(): Abstract method for provider-specific event dispatch
 *
 * @since 1.0.0
 */
abstract class ContractCreationHandler implements HandlerInterface
{
    public function __construct(
        protected readonly ContractServiceInterface $contractService,
        protected readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * Provider specifies which event class it handles.
     *
     * @return string Fully qualified class name of the event
     */
    abstract public static function getHandledEventClass(): string;

    /**
     * Template method - final to enforce the pattern.
     *
     * Common flow:
     * 1. Validate event type
     * 2. Check if contract already exists (skip if so)
     * 3. Validate required context data (userId, basket)
     * 4. Create contract via ContractService
     * 5. Call afterContractCreated() hook for provider-specific logic
     * 6. Set contract on context
     * 7. Dispatch provider-specific event
     */
    public function handle(object $event): void
    {
        // Check if event is the right type for this handler
        if (!is_a($event, static::getHandledEventClass(), false)) {
            return;
        }

        // Event must have getContext() method
        if (!method_exists($event, 'getContext')) {
            return;
        }

        /** @var EventContextInterface $context */
        $context = $event->getContext();

        // Skip if contract already exists (idempotency)
        if ($context->getContract() !== null) {
            return;
        }

        // Validate userId
        $userId = $context->get('userId');
        if (!is_string($userId) || $userId === '') {
            throw new InvalidArgumentException('User ID is required');
        }

        // Validate basket
        $basket = $context->get('basket');
        if (!is_object($basket)) {
            throw new InvalidArgumentException('Basket is required');
        }

        // Validate and extract condition types
        $conditionTypes = $context->get('conditionTypes', []);
        if (!is_array($conditionTypes)) {
            $conditionTypes = [];
        }

        /** @var array<int, string> $validatedConditionTypes */
        $validatedConditionTypes = array_values(array_filter($conditionTypes, 'is_string'));

        // Create contract via service
        $contract = $this->contractService->createContract(
            $userId,
            $basket,
            $validatedConditionTypes
        );

        // Hook for provider-specific post-creation logic
        $this->afterContractCreated($contract, $context);

        // Set contract on context
        $context->setContract($contract);

        // Dispatch provider-specific event
        $this->dispatchContractEvent($contract, $context);
    }

    /**
     * Hook for provider-specific post-creation logic.
     *
     * Override in subclasses to add provider-specific operations like:
     * - Storing metadata
     * - Saving to repository
     * - Logging
     *
     * Default: no-op
     *
     * @param PaymentContractInterface $contract The created contract
     * @param EventContextInterface $context The event context
     */
    protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        // No-op by default - subclasses can override
    }

    /**
     * Dispatch provider-specific event after contract creation.
     *
     * Subclasses must implement this to dispatch their specific event:
     * - Component: ContractCreatedEvent
     * - Stripe: ContractDraftCompletedEvent
     *
     * @param PaymentContractInterface $contract The created contract
     * @param EventContextInterface $context The event context
     */
    abstract protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void;
}
