<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

/**
 * Where the return token's signing secrets live. The newest secret signs; every secret still
 * stored verifies, so a rotation (add a newer one) does not strand tokens in shoppers' hands,
 * and deleting an old one revokes what it signed.
 */
interface ReturnTokenSecretStoreInterface
{
    /**
     * @throws \RuntimeException when no secret exists (the migration creates the first one)
     */
    public function current(): ReturnTokenSecret;

    public function find(string $keyId): ?ReturnTokenSecret;
}
