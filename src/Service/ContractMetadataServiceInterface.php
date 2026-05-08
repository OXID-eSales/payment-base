<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;

/**
 * Service interface for managing contract metadata.
 *
 * Sprint 27: Moved from Stripe to payment-base.
 * Sprint 21: Extract business logic from StripeContractCreationHandler.
 *
 * SOLID Principles:
 * - SRP: Handles contract metadata operations only
 * - OCP: Can be extended for different metadata sources
 * - DIP: Handlers depend on this abstraction
 * - ISP: Focused interface for metadata operations only
 *
 * @since 2.0.0
 */
interface ContractMetadataServiceInterface
{
    /**
     * Store delivery address hash in contract metadata.
     *
     * OXID validates that the delivery address hasn't changed between
     * payment initiation and order finalization. When returning from payment provider,
     * we need to restore the original hash to pass this validation.
     *
     * @param PaymentContractInterface $contract The contract to store metadata on
     * @param object $basket The basket object with user/address info
     */
    public function storeDeliveryAddressMetadata(PaymentContractInterface $contract, object $basket): void;

    /**
     * Store security metadata for session restoration validation.
     *
     * This data is used by ReturnSessionSecurityService to validate
     * that the returning user is the same person who initiated payment.
     *
     * @param PaymentContractInterface $contract The contract to store metadata on
     * @param EventContextInterface $context The event context with session data
     */
    public function storeSecurityMetadata(PaymentContractInterface $contract, EventContextInterface $context): void;

    /**
     * Get delivery address hash from contract metadata.
     *
     * @param PaymentContractInterface $contract The contract to read from
     * @return string|null The stored address hash, or null if not set
     */
    public function getDeliveryAddressHash(PaymentContractInterface $contract): ?string;

    /**
     * Get delivery address ID from contract metadata.
     *
     * @param PaymentContractInterface $contract The contract to read from
     * @return string|null The stored address ID, or null if not set
     */
    public function getDeliveryAddressId(PaymentContractInterface $contract): ?string;
}
