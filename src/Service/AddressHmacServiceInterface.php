<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Interface for HMAC signing/verification of delivery address hashes.
 *
 * Sprint 68b (M9): Prevents hash forgery by attackers with DB write access.
 *
 * @since 2.1.0
 */
interface AddressHmacServiceInterface
{
    public function sign(string $addressHash): string;

    public function verify(string $addressHash, string $hmac): bool;
}
