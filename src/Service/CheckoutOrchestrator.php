<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\OrderCompletedEvent;
use OxidEsales\PaymentComponent\Service\Result\CheckoutResult;
use OxidEsales\PaymentComponent\Service\Result\OrderConfirmationResult;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates checkout accounting for external payment providers.
 *
 * Note: No provider API calls here - payment happens on frontend.
 * This service only handles backend accounting (contract creation, event dispatch).
 *
 * @since 1.0.0
 */
class CheckoutOrchestrator implements CheckoutOrchestratorInterface
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function processCheckout(
        object $basket,
        object $user,
        string $paymentMethodId,
        ?string $providerTransactionId = null
    ): CheckoutResult {
        // Validate basket
        if ($this->isBasketEmpty($basket)) {
            return CheckoutResult::failure('Basket is empty', 'EMPTY_BASKET');
        }

        // Validate user
        if (!$this->isUserValid($user)) {
            return CheckoutResult::failure('Invalid user', 'INVALID_USER');
        }

        try {
            // Create event context with all necessary data
            $context = new EventContext([
                'basket' => $basket,
                'user' => $user,
                'userId' => $this->getUserId($user),
                'paymentMethodId' => $paymentMethodId,
                'providerTransactionId' => $providerTransactionId,
            ]);

            // Get basket data for event
            $amount = $this->getBasketAmount($basket);
            $currency = $this->getBasketCurrency($basket);

            // Dispatch PaymentInitiatedEvent
            // ContractCreationHandler will create the contract and set it in context
            $event = new PaymentInitiatedEvent(
                $context,
                $paymentMethodId,
                $amount,
                $currency,
                '', // returnUrl - not needed for backend accounting
                ''  // cancelUrl - not needed for backend accounting
            );
            $this->eventDispatcher->dispatch($event);

            // Get contract from context (set by handler)
            $contract = $context->getContract();
            if (!$contract instanceof PaymentContractInterface) {
                return CheckoutResult::failure(
                    'Contract creation failed',
                    'CONTRACT_CREATION_FAILED'
                );
            }

            $contractId = $contract->getId();
            if ($contractId === null) {
                return CheckoutResult::failure(
                    'Contract ID is null',
                    'CONTRACT_CREATION_FAILED'
                );
            }

            return CheckoutResult::success($contractId);
        } catch (Throwable $e) {
            $this->logger?->error('Checkout processing failed', [
                'error' => $e->getMessage(),
                'paymentMethodId' => $paymentMethodId,
            ]);

            return CheckoutResult::failure(
                'Checkout processing failed: ' . $e->getMessage(),
                'PROCESSING_ERROR'
            );
        }
    }

    public function confirmOrderCompletion(
        string $orderId,
        ?string $contractId = null
    ): OrderConfirmationResult {
        if ($contractId === null) {
            return OrderConfirmationResult::failure(
                'No contract ID provided',
                OrderConfirmationResult::STATE_FAILED
            );
        }

        try {
            // Create context for order completion
            $context = new EventContext([
                'orderId' => $orderId,
                'contractId' => $contractId,
            ]);

            // Dispatch OrderCompletedEvent
            $event = new OrderCompletedEvent(
                $context,
                $orderId,
                $contractId // Using contractId as providerOrderId for now
            );
            $this->eventDispatcher->dispatch($event);

            // Get contract state from context (updated by handler)
            $contract = $context->getContract();
            $state = $contract?->getStateValue() ?? OrderConfirmationResult::STATE_COMMITTED;

            return OrderConfirmationResult::success($state);
        } catch (Throwable $e) {
            $this->logger?->error('Order confirmation failed', [
                'orderId' => $orderId,
                'contractId' => $contractId,
                'error' => $e->getMessage(),
            ]);

            return OrderConfirmationResult::failure(
                'Order confirmation failed: ' . $e->getMessage()
            );
        }
    }

    private function isBasketEmpty(object $basket): bool
    {
        if (!method_exists($basket, 'getProductsCount')) {
            return false;
        }
        return $basket->getProductsCount() === 0;
    }

    private function isUserValid(object $user): bool
    {
        if (!method_exists($user, 'getId')) {
            return false;
        }
        $id = $user->getId();
        return $id !== null && $id !== '';
    }

    private function getUserId(object $user): string
    {
        if (method_exists($user, 'getId')) {
            return (string) $user->getId();
        }
        return '';
    }

    private function getBasketAmount(object $basket): float
    {
        if (method_exists($basket, 'getBruttoSum')) {
            return (float) $basket->getBruttoSum();
        }
        return 0.0;
    }

    private function getBasketCurrency(object $basket): string
    {
        if (method_exists($basket, 'getBasketCurrency')) {
            $currency = $basket->getBasketCurrency();
            if (is_object($currency) && isset($currency->name)) {
                return (string) $currency->name;
            }
        }
        return 'EUR';
    }
}
