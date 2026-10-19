<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

/**
 * The `tokenid` claim of the request's bearer JWT; the sealed envelope is
 * bound to it. Empty string when there is none.
 *
 * @since 1.4.0
 */
interface TokenIdReaderInterface
{
    public function tokenId(): string;
}
