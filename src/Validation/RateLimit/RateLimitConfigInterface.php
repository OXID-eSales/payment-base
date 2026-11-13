<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

/**
 * The validation endpoint's limit: requests per visitor (client address) and minute, the same for every plugin.
 *
 * Sprint 29 (VRL, Mythos O-08): was `getLimitForPlugin(string)`; the limit stopped depending on the plugin id
 * when the counter stopped being keyed on it.
 */
interface RateLimitConfigInterface
{
    public function getLimitPerMinute(): int;
}
