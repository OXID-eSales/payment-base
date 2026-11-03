<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

/**
 * The admin of the classic admin's session.
 *
 * @since 1.7.0
 */
interface ClassicAdminSessionInterface
{
    /** The OXID user id of the logged-in admin; empty when nobody is logged in. */
    public function userId(): string;

    public function shopId(): int;
}
