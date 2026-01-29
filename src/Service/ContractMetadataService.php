<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;

/**
 * Service for managing contract metadata.
 *
 * Sprint 27: Moved from Stripe to payment-component.
 * Sprint 21: Extract business logic from StripeContractCreationHandler.
 *
 * SOLID Principles:
 * - SRP: Handles contract metadata operations only
 * - OCP: Can be extended for different metadata sources
 * - DIP: Depends on abstractions
 * - ISP: Focused interface for metadata operations only
 *
 * Note: This is a provider-agnostic implementation that uses $_SESSION directly.
 * Platform-specific implementations can extend this class to use platform session APIs.
 *
 * @since 2.0.0
 */
class ContractMetadataService implements ContractMetadataServiceInterface
{
    /**
     * @inheritDoc
     */
    public function storeDeliveryAddressMetadata(PaymentContractInterface $contract, object $basket): void
    {
        $addressHash = $this->getAddressHashFromSession();
        $deliveryAddressId = $this->getDeliveryAddressIdFromSession();

        // If no hash in session, compute from user
        if (empty($addressHash)) {
            $addressHash = $this->computeAddressHashFromBasket($basket);
        }

        // Store the hash in contract metadata
        if (!empty($addressHash)) {
            $contract->setMetadata('delivery_address_hash', $addressHash);
        }

        // Also store delivery address ID if present
        if (!empty($deliveryAddressId)) {
            $contract->setMetadata('delivery_address_id', $deliveryAddressId);
        }
    }

    /**
     * @inheritDoc
     */
    public function storeSecurityMetadata(PaymentContractInterface $contract, EventContextInterface $context): void
    {
        // Store user IP address
        $userIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $contract->setMetadata('user_ip', $userIp);

        // Store user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $contract->setMetadata('user_agent', $userAgent);

        // Store creation timestamp
        $contract->setMetadata('created_timestamp', time());

        // Store PHP session ID if provided in context
        $phpSessionId = $context->get('phpSessionId');
        if (is_string($phpSessionId) && $phpSessionId !== '') {
            $contract->setMetadata('session_id', $phpSessionId);
        }

        // Store user country if provided in context
        $userCountry = $context->get('userCountry');
        if (is_string($userCountry) && $userCountry !== '') {
            $contract->setMetadata('user_country', $userCountry);
        }
    }

    /**
     * @inheritDoc
     */
    public function getDeliveryAddressHash(PaymentContractInterface $contract): ?string
    {
        $hash = $contract->getMetadata('delivery_address_hash');
        return is_string($hash) ? $hash : null;
    }

    /**
     * @inheritDoc
     */
    public function getDeliveryAddressId(PaymentContractInterface $contract): ?string
    {
        $id = $contract->getMetadata('delivery_address_id');
        return is_string($id) ? $id : null;
    }

    /**
     * Get address hash from PHP session.
     *
     * Uses standard $_SESSION superglobal for provider-agnostic implementation.
     */
    protected function getAddressHashFromSession(): ?string
    {
        if (isset($_SESSION['sDelAddrMD5']) && !empty($_SESSION['sDelAddrMD5'])) {
            return (string) $_SESSION['sDelAddrMD5'];
        }
        return null;
    }

    /**
     * Get delivery address ID from PHP session.
     *
     * Uses standard $_SESSION superglobal for provider-agnostic implementation.
     */
    protected function getDeliveryAddressIdFromSession(): ?string
    {
        if (isset($_SESSION['deladrid']) && !empty($_SESSION['deladrid'])) {
            return (string) $_SESSION['deladrid'];
        }
        return null;
    }

    /**
     * Compute address hash from basket user.
     *
     * Uses duck typing to work with any basket object that has getBasketUser().
     */
    protected function computeAddressHashFromBasket(object $basket): ?string
    {
        if (!method_exists($basket, 'getBasketUser')) {
            return null;
        }

        $user = $basket->getBasketUser();
        if ($user === null || !is_object($user)) {
            return null;
        }

        if (!method_exists($user, 'getEncodedDeliveryAddress')) {
            return null;
        }

        $hash = $user->getEncodedDeliveryAddress();
        return !empty($hash) ? (string) $hash : null;
    }
}
