<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * Who an anonymous shopper is, for a checkout without an account: e-mail,
 * invoice address and optionally a different delivery address. Validated on
 * construction (HeadlessCheckoutException `guest_details_required`).
 *
 * @since 1.5.0
 */
final readonly class GuestDetails
{
    public function __construct(
        public string $email,
        public GuestAddress $invoiceAddress,
        public ?GuestAddress $deliveryAddress = null,
    ) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw HeadlessCheckoutException::guestDetailsRequired('email');
        }
    }
}
