<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

interface ReturnAccessSettingsInterface
{
    /**
     * How long a return token lives, in seconds.
     */
    public function tokenTtlSeconds(): int;

    /**
     * Whether a provider's pre-VR-20 return token (no expiry, no shopper) still opens its return
     * leg. A transition switch for the release that introduces the shared token; removed with it.
     */
    public function acceptsLegacyTokens(): bool;
}
