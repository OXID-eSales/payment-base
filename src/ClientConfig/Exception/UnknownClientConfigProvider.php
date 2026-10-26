<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Exception;

/**
 * Client-safe: the client asked for a provider this
 * shop does not have. The message only repeats what the client sent.
 *
 * @since 1.4.0
 */
final class UnknownClientConfigProvider extends ClientConfigRequestRefused
{
    public function __construct(string $providerId)
    {
        parent::__construct(
            message: sprintf('Unknown payment provider "%s"', $providerId)
        );
    }
}
