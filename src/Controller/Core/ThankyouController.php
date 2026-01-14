<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Controller\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Application\Controller\ThankYouController as OxidThankyouController;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface;
use OxidEsales\PaymentComponent\Traits\ServiceContainer;
use Throwable;

/**
 * Extended ThankyouController for external payment order completion accounting.
 *
 * This is a provider-agnostic base controller that handles contract-based
 * order completion. Provider-specific controllers can extend this class
 * and override methods as needed.
 *
 * Note: Final payment confirmation happens via webhook.
 * This controller only confirms the order was placed and transitions contract state.
 *
 * @since 1.0.0
 */
class ThankyouController extends OxidThankyouController
{
    use ServiceContainer;

    /**
     * Session key for contract ID.
     * Must match the key used in OrderController.
     */
    protected const SESSION_CONTRACT_ID = 'payment_contract_id';

    /**
     * Session key for provider transaction ID.
     * Provider-agnostic naming.
     */
    protected const SESSION_PROVIDER_TRANSACTION_ID = 'payment_provider_transaction_id';

    /**
     * Renders the thankyou page.
     *
     * For external payment methods:
     * 1. Confirms order completion via orchestrator
     * 2. Cleans up session variables
     * 3. Logs state for debugging
     *
     * @return string Template name
     */
    public function render(): string
    {
        $contractId = $this->getContractIdFromSession();

        if ($contractId !== null) {
            $this->confirmOrderCompletion($contractId);
        }

        return $this->renderParent();
    }

    /**
     * Confirms order completion for external payment.
     * Provider-agnostic implementation.
     */
    protected function confirmOrderCompletion(string $contractId): void
    {
        $order = $this->getOrder();
        if ($order === null) {
            $this->logError('Order not found in thankyou controller', [
                'contractId' => $contractId,
            ]);
            return;
        }

        $orderId = $order->getId();

        try {
            $result = $this->getCheckoutOrchestrator()->confirmOrderCompletion(
                $orderId,
                $contractId
            );

            if ($result->isSuccess()) {
                // Cleanup session - contract is now linked to order
                $this->clearSessionVariables();

                // Log state for debugging
                if ($result->isAwaitingPaymentConfirmation()) {
                    $this->logInfo('Order awaiting payment confirmation via webhook', [
                        'orderId' => $orderId,
                        'contractId' => $contractId,
                        'state' => $result->getContractState(),
                    ]);
                }

                if ($result->isFullyCompleted()) {
                    $this->logInfo('Order fully completed', [
                        'orderId' => $orderId,
                        'contractId' => $contractId,
                    ]);
                }
            } else {
                $this->logError('Failed to confirm order completion', [
                    'orderId' => $orderId,
                    'contractId' => $contractId,
                    'error' => $result->getErrorMessage(),
                ]);
            }
        } catch (Throwable $e) {
            // Log but don't break the thankyou page
            $this->logError('Exception during order confirmation', [
                'orderId' => $orderId,
                'contractId' => $contractId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gets contract ID from session.
     */
    protected function getContractIdFromSession(): ?string
    {
        return $this->getSession()->getVariable(static::SESSION_CONTRACT_ID);
    }

    /**
     * Clears session variables after successful confirmation.
     * Override in provider-specific controllers to clear additional variables.
     */
    protected function clearSessionVariables(): void
    {
        $session = $this->getSession();
        $session->deleteVariable(static::SESSION_CONTRACT_ID);
        $session->deleteVariable(static::SESSION_PROVIDER_TRANSACTION_ID);
    }

    /**
     * Gets the session.
     */
    protected function getSession(): object
    {
        return Registry::getSession();
    }

    /**
     * Logs an info message.
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Registry::getLogger()->info($message, $context);
    }

    /**
     * Logs an error message.
     */
    protected function logError(string $message, array $context = []): void
    {
        Registry::getLogger()->error($message, $context);
    }

    /**
     * Calls parent render method.
     * Extracted for easier testing.
     */
    protected function renderParent(): string
    {
        return parent::render();
    }

    /**
     * Gets the CheckoutOrchestrator from DI container.
     */
    protected function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        return $this->getServiceFromContainer(CheckoutOrchestratorInterface::class);
    }
}
