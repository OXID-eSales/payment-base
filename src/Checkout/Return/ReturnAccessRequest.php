<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;

/**
 * Everything the guard needs to know about one return leg. `$legacyTokens` is the provider's
 * pre-VR-20 token validator, passed only while that provider still issues such tokens; the guard
 * asks it only when the legacy switch is on ({@see ReturnAccessSettingsInterface}). Removed with
 * the switch.
 */
final readonly class ReturnAccessRequest
{
    public function __construct(
        public ?PaymentContractInterface $contract,
        public string $provider,
        public string $token,
        public string $sessionUserId,
        public string $sessionId,
        public ?TokenServiceInterface $legacyTokens = null,
    ) {
    }
}
