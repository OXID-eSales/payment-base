<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use RuntimeException;

/**
 * Why a headless checkout call was refused. `$errorCode` is stable and
 * machine-readable; the message is for the developer reading the GraphQL
 * error.
 *
 * @since 3.0.0
 */
final class HeadlessCheckoutException extends RuntimeException
{
    public const BASKET_NOT_FOUND = 'basket_not_found';

    public const PAYMENT_NOT_SUPPORTED = 'payment_not_supported';

    public const TERMS_NOT_CONFIRMED = 'terms_not_confirmed';

    public const RETURN_URL_REJECTED = 'return_url_rejected';

    public const USER_NOT_FOUND = 'user_not_found';

    public const PROVIDER_FAILED = 'provider_failed';

    public const CONTRACT_NOT_FOUND = 'contract_not_found';

    public const INVALID_TOKEN = 'invalid_token';

    public const NO_RETURN_RESOLVER = 'no_return_resolver';

    /** An anonymous token started a checkout without guest details, or the details are incomplete or invalid. */
    public const GUEST_DETAILS_REQUIRED = 'guest_details_required';

    /** The guest's e-mail belongs to a registered account: the shopper has to log in. */
    public const ACCOUNT_EXISTS = 'account_exists';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $providerCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Names the field, never its value: the value is personal data and the message reaches the shop log.
     */
    public static function guestDetailsRequired(string $field): self
    {
        return new self(self::GUEST_DETAILS_REQUIRED, sprintf('Guest details: %s is missing or invalid', $field));
    }
}
