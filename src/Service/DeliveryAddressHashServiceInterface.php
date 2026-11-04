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
 * Encapsulates $_REQUEST modification for delivery address validation.
 *
 * OXID's Order::validateDeliveryAddress() reads 'sDeliveryAddressMD5' through
 * Registry::getRequest(), i.e. from $_POST, then $_GET (never $_REQUEST).
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
     * Sets the hash where OXID reads it during Order::validateDeliveryAddress()
     * ($_POST, mirrored into $_REQUEST for this service's own readers).
     * This is necessary because returning from payment provider loses the original form POST data.
     *
     * @param string|null $hash MD5 hash of delivery address, or null to skip
     */
    public function restoreHashForValidation(?string $hash): void;
}
