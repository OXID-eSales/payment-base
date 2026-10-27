<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Exception;

/**
 * Client-safe: `clientPublicKey` is not a raw P-256 point (65 bytes,
 * 0x04 || X || Y, base64url).
 *
 * @since 1.4.0
 */
final class InvalidClientPublicKey extends ClientConfigRequestRefused
{
    public function __construct(string $reason)
    {
        parent::__construct(
            message: 'Invalid clientPublicKey: ' . $reason
        );
    }
}
