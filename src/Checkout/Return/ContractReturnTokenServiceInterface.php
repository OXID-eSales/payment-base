<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

interface ContractReturnTokenServiceInterface
{
    /**
     * A URL-safe token that lets whoever holds it back into the return leg of this contract, until
     * it expires. `$userId` is the shopper it is issued to; null issues an unbound token.
     */
    public function generateToken(string $contractId, ?string $userId = null): string;

    /**
     * What the token says about this contract and its owner: signature, contract id and shopper
     * are checked before expiry, so a forged token is never told it merely "expired".
     */
    public function verify(string $token, string $contractId, string $contractUserId): ReturnTokenVerdict;
}
