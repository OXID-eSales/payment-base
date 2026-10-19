<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Exception;

use RuntimeException;

/**
 * A provider tried to hand a secret (or a non string/bool value) to the
 * browser. A bug in the provider, not in the request: deliberately NOT
 * client-safe, so the client only sees a generic server error. The message
 * names the offending KEY, never the value.
 *
 * @since 1.4.0
 */
final class UnsafeClientConfig extends RuntimeException
{
    public static function forKey(string $key, string $why): self
    {
        return new self(sprintf('Refusing to send client config "%s" to a browser: %s', $key, $why));
    }
}
