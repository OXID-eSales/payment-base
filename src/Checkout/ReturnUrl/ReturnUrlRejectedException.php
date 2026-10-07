<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\ReturnUrl;

use InvalidArgumentException;

/**
 * A client-supplied return URL the policy will not send a shopper to.
 *
 * @since 3.0.0
 */
final class ReturnUrlRejectedException extends InvalidArgumentException
{
    public const NOT_ABSOLUTE = 'not_absolute';

    public const SCHEME_NOT_ALLOWED = 'scheme_not_allowed';

    public const CREDENTIALS_NOT_ALLOWED = 'credentials_not_allowed';

    public const ORIGIN_NOT_ALLOWED = 'origin_not_allowed';

    public function __construct(
        public readonly string $url,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('Return URL rejected (%s): %s', $reason, $url));
    }
}
