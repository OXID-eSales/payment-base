<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

/**
 * Provider-agnostic payment customer repository interface.
 *
 * This interface allows any payment provider (Stripe, PayPal, etc.) to store
 * and retrieve customer mappings without coupling to a specific implementation.
 *
 * LSP Compliance: Any implementation can be substituted without affecting clients.
 *
 * Sprint 2: Replaces Stripe-specific osc_stripe_customer_mapping with
 * provider-agnostic osc_payment_customer table.
 */
interface PaymentCustomerRepositoryInterface
{
    /**
     * Find customer record by OXID user ID.
     *
     * @param string $userId OXID user ID
     * @return array<string, mixed>|null Customer data or null if not found
     */
    public function findByUserId(string $userId): ?array;

    /**
     * Save customer data.
     *
     * @param array<string, mixed> $data Customer data to save
     *        Required keys: userId, paymentCustomerId
     *        Optional keys: defaultPaymentMethod, savedPaymentMethods, billingAgreement
     * @return void
     */
    public function save(array $data): void;

    /**
     * Find payment customer ID by user ID.
     *
     * Convenience method for retrieving just the payment provider's customer ID.
     *
     * @param string $userId OXID user ID
     * @return string|null Payment customer ID (e.g., Stripe's cus_xxx) or null
     */
    public function findPaymentCustomerId(string $userId): ?string;

    /**
     * Save payment customer ID for a user.
     *
     * Convenience method for storing the payment provider's customer ID.
     *
     * @param string $userId OXID user ID
     * @param string $paymentCustomerId Payment provider customer ID
     * @return void
     */
    public function savePaymentCustomerId(string $userId, string $paymentCustomerId): void;
}
