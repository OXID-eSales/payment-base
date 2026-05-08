<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Interface for delivery address hash management.
 *
 * Sprint 27: Moved from Stripe to payment-base.
 * Sprint 20: Encapsulates $_REQUEST modification for delivery address validation.
 *
 * OXID's Order::validateDeliveryAddress() reads from $_REQUEST['sDeliveryAddressMD5'].
 * When returning from payment checkout, the original form data is lost, so we need
 * to restore this value for validation to pass.
 *
 * SOLID Principles:
 * - SRP: Single responsibility - delivery address hash for OXID validation
 * - ISP: Focused interface with hash operations only
 * - DIP: Handlers depend on this abstraction, not direct $_REQUEST manipulation
 *
 * @since 2.0.0
 */
interface DeliveryAddressHashServiceInterface
{
    /**
     * Restore delivery address hash for OXID validation.
     *
     * Sets the hash in $_REQUEST where OXID expects it during Order::validateDeliveryAddress().
     * This is necessary because returning from payment provider loses the original form POST data.
     *
     * @param string|null $hash MD5 hash of delivery address, or null to skip
     */
    public function restoreHashForValidation(?string $hash): void;

    /**
     * Get current delivery address hash from $_REQUEST.
     *
     * @return string|null Hash or null if not set
     */
    public function getHash(): ?string;

    /**
     * Check if delivery address hash exists in $_REQUEST.
     *
     * @return bool True if hash is set
     */
    public function hasHash(): bool;

    /**
     * Clear delivery address hash from $_REQUEST.
     */
    public function clearHash(): void;
}
