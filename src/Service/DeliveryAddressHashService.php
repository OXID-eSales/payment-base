<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Service for delivery address hash management.
 *
 * Sprint 27: Moved from Stripe to payment-component.
 * Sprint 20: Encapsulates $_REQUEST modification for delivery address validation.
 *
 * WHY $_REQUEST MODIFICATION IS NECESSARY:
 * OXID's Order::validateDeliveryAddress() (line ~2100) reads the hash from:
 *   Registry::getRequest()->getRequestEscapedParameter('sDeliveryAddressMD5')
 *
 * This ultimately reads from $_REQUEST. When returning from payment checkout,
 * the original form POST data is lost. To make OXID's validation pass,
 * we must inject the hash back into $_REQUEST.
 *
 * This service encapsulates this anti-pattern in one documented location,
 * making it:
 * - Testable (mock the service, not $_REQUEST)
 * - Documented (clear why it's necessary)
 * - Isolated (only this service touches $_REQUEST)
 *
 * SOLID Principles:
 * - SRP: Only handles delivery address hash for validation
 * - OCP: Open for extension via interface
 * - DIP: Handlers depend on interface abstraction
 *
 * @since 2.0.0
 */
class DeliveryAddressHashService implements DeliveryAddressHashServiceInterface
{
    private const REQUEST_KEY = 'sDeliveryAddressMD5';

    public function restoreHashForValidation(?string $hash): void
    {
        if ($hash === null || $hash === '') {
            return;
        }

        // phpcs:ignore
        $_REQUEST[self::REQUEST_KEY] = $hash;
    }

    public function getHash(): ?string
    {
        // phpcs:ignore
        $hash = $_REQUEST[self::REQUEST_KEY] ?? null;

        return is_string($hash) ? $hash : null;
    }

    public function hasHash(): bool
    {
        // phpcs:ignore
        return isset($_REQUEST[self::REQUEST_KEY]) && $_REQUEST[self::REQUEST_KEY] !== '';
    }

    public function clearHash(): void
    {
        // phpcs:ignore
        unset($_REQUEST[self::REQUEST_KEY]);
    }
}
