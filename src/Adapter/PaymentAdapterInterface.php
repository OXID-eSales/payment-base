<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter;

use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\VoidPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CaptureAuthorizationRequest;
use OxidEsales\PaymentComponent\Adapter\Request\VoidAuthorizationRequest;
use OxidEsales\PaymentComponent\Adapter\Request\ReauthorizePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentMethodRequest;
use OxidEsales\PaymentComponent\Adapter\Request\ThreeDSecureRequest;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Adapter\Response\VoidResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentComponent\Adapter\Response\AuthorizationResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentMethodResponse;
use OxidEsales\PaymentComponent\Adapter\Response\ThreeDSecureResponse;
use OxidEsales\PaymentComponent\Adapter\Exception\PaymentAdapterException;

/**
 * Unified interface for all payment provider adapters.
 *
 * All provider-specific adapters (Stripe, Unzer, PayPal, etc.) MUST implement this interface.
 * This ensures consistent interaction patterns across all providers.
 *
 * Based on comprehensive analysis of multiple providers:
 * - Stripe, Unzer, TeleCash, PayPal, Amazon Pay
 *
 * Design Principles:
 * - Provider-agnostic request/response objects (no domain object leakage)
 * - Supports advanced features (two-step auth, vaulting, 3DS)
 * - Feature detection via supportsFeature()
 * - Webhook processing in adapter layer
 * - Clean exceptions for error handling
 *
 * @since 1.0.0
 * @version 2.0.0 (Enhanced with vaulting, 3DS, two-step auth)
 */
interface PaymentAdapterInterface
{
    // ==========================================
    // BASIC PAYMENT OPERATIONS
    // ==========================================

    /**
     * Create a new payment (authorization or direct capture).
     *
     * @param CreatePaymentRequest $request Normalized payment request
     * @return PaymentResponse Provider-agnostic response
     * @throws PaymentAdapterException On provider errors
     */
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;

    /**
     * Capture a previously authorized payment.
     *
     * @param CapturePaymentRequest $request Capture request with amount
     * @return CaptureResponse Capture result
     * @throws PaymentAdapterException On provider errors
     */
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;

    /**
     * Refund a captured payment (full or partial).
     *
     * @param RefundPaymentRequest $request Refund request with amount
     * @return RefundResponse Refund result
     * @throws PaymentAdapterException On provider errors
     */
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;

    /**
     * Void (cancel) an authorized payment before capture.
     *
     * @param VoidPaymentRequest $request Void request
     * @return VoidResponse Void result
     * @throws PaymentAdapterException On provider errors
     */
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;

    /**
     * Get payment details by provider payment ID.
     *
     * @param string $providerPaymentId Provider's payment identifier
     * @return PaymentDetailsResponse Payment details
     * @throws PaymentAdapterException On provider errors
     */
    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;

    // ==========================================
    // TWO-STEP AUTHORIZATION (PayPal, Unzer, Stripe)
    // ==========================================

    /**
     * Authorize payment without capturing funds.
     *
     * Required by: PayPal, Unzer, Stripe
     * Use case: Reserve funds at checkout, capture at shipping
     *
     * @param AuthorizePaymentRequest $request Authorization request
     * @return AuthorizationResponse Authorization result with expiry
     * @throws PaymentAdapterException On provider errors
     */
    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse;

    /**
     * Capture a previously authorized payment.
     *
     * @param CaptureAuthorizationRequest $request Capture request
     * @return CaptureResponse Capture result
     * @throws PaymentAdapterException On provider errors
     */
    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse;

    /**
     * Void (cancel) an authorization before capture.
     *
     * @param VoidAuthorizationRequest $request Void request
     * @return VoidResponse Void result
     * @throws PaymentAdapterException On provider errors
     */
    public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse;

    /**
     * Reauthorize an expired authorization.
     *
     * Required by: PayPal (authorizations expire after 3-29 days)
     *
     * @param ReauthorizePaymentRequest $request Reauthorization request
     * @return AuthorizationResponse New authorization with new expiry
     * @throws PaymentAdapterException On provider errors
     */
    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse;

    // ==========================================
    // VAULTING / TOKENIZATION (Saved Payment Methods)
    // ==========================================

    /**
     * Create and save a payment method for future use.
     *
     * Required by: PayPal, Stripe, Unzer, Amazon Pay
     * Use cases:
     * - Save credit card for future purchases
     * - Recurring payments
     * - One-click checkout
     *
     * @param CreatePaymentMethodRequest $request Payment method creation request
     * @return PaymentMethodResponse Created payment method details
     * @throws PaymentAdapterException On provider errors
     */
    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse;

    /**
     * List all saved payment methods for a customer.
     *
     * @param string $customerId Provider customer ID
     * @return array<PaymentMethodResponse> List of saved payment methods
     * @throws PaymentAdapterException On provider errors
     */
    public function listPaymentMethods(string $customerId): array;

    /**
     * Delete a saved payment method.
     *
     * @param string $paymentMethodId Payment method ID to delete
     * @return bool True if successfully deleted
     * @throws PaymentAdapterException On provider errors
     */
    public function deletePaymentMethod(string $paymentMethodId): bool;

    // ==========================================
    // 3D SECURE / SCA (Strong Customer Authentication)
    // ==========================================

    /**
     * Initiate 3D Secure authentication challenge.
     *
     * Required by: PayPal, Stripe, Unzer, TeleCash
     * Required for: PSD2 compliance in Europe
     *
     * @param ThreeDSecureRequest $request 3DS request
     * @return ThreeDSecureResponse Challenge URL and session
     * @throws PaymentAdapterException On provider errors
     */
    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse;

    /**
     * Verify 3D Secure authentication result.
     *
     * @param string $providerPaymentId Provider payment ID
     * @return bool True if authentication successful
     * @throws PaymentAdapterException On provider errors
     */
    public function verify3DSecureResult(string $providerPaymentId): bool;

    // ==========================================
    // PROVIDER METADATA & CAPABILITIES
    // ==========================================

    /**
     * Get supported payment methods for this adapter.
     *
     * @return array<string> List of payment method identifiers
     * Example: ['card', 'ideal', 'sepa_debit', 'sofort']
     */
    public function getSupportedPaymentMethods(): array;

    /**
     * Get the provider name.
     *
     * @return string Provider identifier (e.g., 'stripe', 'unzer', 'paypal')
     */
    public function getProviderName(): string;

    /**
     * Check if this adapter supports a specific feature.
     *
     * Features:
     * - 'partial_refund': Supports refunding part of payment
     * - 'partial_capture': Supports capturing part of authorization
     * - 'recurring': Supports recurring/subscription payments
     * - 'saved_cards': Supports saving payment methods (vaulting)
     * - 'webhooks': Supports webhook notifications
     * - '3ds': Supports 3D Secure authentication
     * - 'installments': Supports installment payments
     * - 'invoice': Supports invoice/pay-later
     *
     * @param string $feature Feature name
     * @return bool True if feature is supported
     */
    public function supportsFeature(string $feature): bool;

    // ==========================================
    // WEBHOOK PROCESSING
    // ==========================================

    /**
     * Validate webhook signature and parse webhook event.
     *
     * This centralizes webhook signature verification in the adapter layer
     * instead of duplicating it in webhook controllers.
     *
     * @param string $payload Raw webhook payload
     * @param string $signature Webhook signature header
     * @param string $secret Webhook secret
     * @return WebhookEvent Parsed and validated webhook event
     * @throws PaymentAdapterException On invalid signature or parsing error
     */
    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent;
}
