<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Service\Result\CheckoutResult;
use OxidEsales\PaymentComponent\Service\Result\OrderConfirmationResult;

/**
 * Orchestrates checkout accounting for external payment providers.
 *
 * Note: This service handles BACKEND ACCOUNTING only.
 * Actual payment processing happens on the frontend via provider SDKs.
 *
 * Responsibilities:
 * - Create payment contracts
 * - Dispatch payment events
 * - Coordinate with event handlers
 * - Return results to controllers
 *
 * @since 1.0.0
 */
interface CheckoutOrchestratorInterface
{
    /**
     * Processes checkout: creates contract, snapshots basket, dispatches events.
     *
     * Called from OrderController::execute() BEFORE parent::execute().
     *
     * Does NOT:
     * - Call provider APIs directly
     * - Process payments
     * - Handle redirects
     *
     * Does:
     * - Create PaymentContract
     * - Snapshot basket data
     * - Store provider transaction ID for later webhook matching
     * - Emit PaymentInitiatedEvent
     *
     * @param object $basket OXID basket object
     * @param object $user OXID user object
     * @param string $paymentMethodId Payment method (e.g., 'stripe_card', 'paypal_express')
     * @param string|null $providerTransactionId Provider transaction ID from frontend (optional)
     * @return CheckoutResult Result containing contract_id or error
     */
    public function processCheckout(
        object $basket,
        object $user,
        string $paymentMethodId,
        ?string $providerTransactionId = null
    ): CheckoutResult;

    /**
     * Confirms order completion and transitions contract state.
     *
     * Called from ThankyouController::render().
     *
     * Transitions contract: PENDING → COMMITTED
     * Final transition to FULFILLED happens via webhook.
     *
     * @param string $orderId OXID order ID
     * @param string|null $contractId Contract ID from session
     * @return OrderConfirmationResult Result with contract state
     */
    public function confirmOrderCompletion(
        string $orderId,
        ?string $contractId = null
    ): OrderConfirmationResult;
}
