<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Controller\Core;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Core\Exception\ArticleInputException;
use OxidEsales\Eshop\Core\Exception\NoArticleException;
use OxidEsales\Eshop\Core\Exception\OutOfStockException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\Eshop\Application\Controller\OrderController as OxidOrderController;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface;
use OxidEsales\PaymentComponent\Traits\ServiceContainer;

/**
 * Extended OrderController for external payment provider accounting.
 *
 * This is a provider-agnostic base controller that handles contract-based
 * payment processing. Provider-specific controllers (e.g., StripeOrderController)
 * should extend this class and override methods as needed.
 *
 * Note: Actual payment processing happens on the frontend via provider SDKs.
 * This controller only handles backend accounting (contract creation, order linking).
 *
 * @since 1.0.0
 */
class OrderController extends OxidOrderController
{
    use ServiceContainer;

    /**
     * Session key for storing contract ID.
     * Provider-agnostic naming.
     */
    protected const SESSION_CONTRACT_ID = 'payment_contract_id';

    /**
     * Executes order placement.
     *
     * For external payment methods (managed by this component):
     * 1. Calls orchestrator to create contract
     * 2. Stores contract ID in session
     * 3. Calls parent to create OXID order
     *
     * For standard payment methods:
     * - Falls back to parent behavior
     *
     * @return mixed View name or redirect
     */
    public function execute(): mixed
    {
        if (!$this->isExternalPaymentMethod()) {
            return $this->executeParentWithExceptionHandling();
        }

        return $this->executeWithContractAccounting();
    }

    /**
     * Processes checkout with contract-based payment accounting.
     * Provider-agnostic implementation.
     */
    protected function executeWithContractAccounting(): mixed
    {
        /** @var Basket $basket */
        $basket = $this->getBasketFromSession();
        $user = $this->getUser();
        $paymentId = (string) $basket->getPaymentId();

        // Get provider transaction ID from request (set by frontend payment SDK)
        $providerTransactionId = $this->getProviderTransactionIdFromRequest();

        $result = $this->getCheckoutOrchestrator()->processCheckout(
            $basket,
            $user,
            $paymentId,
            $providerTransactionId
        );

        if (!$result->isSuccess()) {
            $this->addErrorToDisplay($result->getErrorMessage() ?? 'Unknown error');
            return $this->getViewName();
        }

        // Store contract ID for ThankyouController
        $contractId = $result->getContractId();
        if ($contractId !== null) {
            /** @var Session $session */
            $session = $this->getSessionForVariables();
            $session->setVariable(static::SESSION_CONTRACT_ID, $contractId);
        }

        // Continue with standard OXID order creation
        return $this->executeParent();
    }

    /**
     * Gets the basket from session.
     * Extracted for easier testing.
     *
     * @return Basket|object
     */
    protected function getBasketFromSession(): object
    {
        return $this->getOxidSession()->getBasket();
    }

    /**
     * Gets session for storing variables.
     * Extracted for easier testing.
     *
     * @return Session|object
     */
    protected function getSessionForVariables(): object
    {
        return $this->getOxidSession();
    }

    /**
     * Checks if the selected payment method is an external payment method
     * managed by this component.
     *
     * Override this method in provider-specific controllers to define
     * which payment method IDs are handled.
     *
     * @return bool
     */
    protected function isExternalPaymentMethod(): bool
    {
        $basket = $this->getOxidSession()->getBasket();
        $paymentId = $basket->getPaymentId();

        if ($paymentId === null || $paymentId === '') {
            return false;
        }

        return $this->isPaymentMethodSupported((string) $paymentId);
    }

    /**
     * Checks if a specific payment method ID is supported by this component.
     *
     * Override this method in provider-specific controllers to define
     * supported payment method prefixes/IDs.
     *
     * @param string $paymentId The payment method ID to check
     * @return bool
     */
    protected function isPaymentMethodSupported(string $paymentId): bool
    {
        // Base implementation returns false - provider-specific controllers
        // should override this to return true for their payment methods
        return false;
    }

    /**
     * Gets provider transaction ID from request.
     *
     * Override this method in provider-specific controllers to read
     * the appropriate request parameter for that provider.
     *
     * @return string|null
     */
    protected function getProviderTransactionIdFromRequest(): ?string
    {
        // Base implementation reads generic parameter name
        // Provider-specific controllers should override for their parameter names
        /** @var string|null $value */
        $value = Registry::getRequest()->getRequestParameter('payment_transaction_id');
        return $value;
    }

    /**
     * Gets the OXID session.
     *
     * @return Session
     */
    protected function getOxidSession(): Session
    {
        /** @var Session $session */
        $session = Registry::getSession();
        return $session;
    }

    /**
     * Adds an error message to display.
     */
    protected function addErrorToDisplay(string $message): void
    {
        Registry::getUtilsView()->addErrorToDisplay($message);
    }

    /**
     * Gets the view name for error display.
     */
    protected function getViewName(): string
    {
        return 'order';
    }

    /**
     * Executes parent with exception handling (preserves original behavior).
     */
    private function executeParentWithExceptionHandling(): mixed
    {
        try {
            return $this->executeParent();
        } catch (NoArticleException | OutOfStockException | ArticleInputException $e) {
            Registry::getSession()->setVariable('OrderException', $e);
            /** @phpstan-ignore-next-line method.notFound - setViewConfigParam exists in parent */
            $this->setViewConfigParam('bOrderStepError', true);

            return $this->getViewName();
        }
    }

    /**
     * Calls parent execute method.
     * Extracted for easier testing.
     */
    protected function executeParent(): mixed
    {
        return parent::execute();
    }

    /**
     * Gets the CheckoutOrchestrator from DI container.
     */
    protected function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        return $this->getServiceFromContainer(CheckoutOrchestratorInterface::class);
    }
}
