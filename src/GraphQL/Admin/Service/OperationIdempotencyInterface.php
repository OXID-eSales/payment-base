<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * One key per call of an admin payment operation, so that a repeated request (a double click, a retry after a
 * timeout) does not send the money twice.
 *
 * @since 1.5.0
 */
interface OperationIdempotencyInterface
{
    /**
     * @param string $discriminator the client's idempotency key, or what makes two calls "the same" without one
     */
    public function claim(string $operation, string $orderId, string $discriminator): IdempotencyClaim;

    public function complete(IdempotencyClaim $claim): void;

    /**
     * The call did not do what it was asked to: the next attempt with the same key goes through.
     */
    public function release(IdempotencyClaim $claim): void;
}
