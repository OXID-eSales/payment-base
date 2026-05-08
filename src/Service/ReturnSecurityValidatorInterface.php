<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\SecurityValidationResultInterface;

/**
 * Interface for security validation of returning users from payment providers.
 *
 * Validates returning user identity and calculates fraud risk score
 * based on IP, timing, user agent, and other factors.
 *
 * Any payment provider can implement this interface with provider-specific
 * validation logic while maintaining consistent behavior (LSP compliance).
 */
interface ReturnSecurityValidatorInterface
{
    /**
     * Validate a returning user against the original contract context.
     *
     * @param PaymentContractInterface $contract The original payment contract
     * @param array<string, mixed> $currentContext Current request context (ip, user_agent, country, etc.)
     * @return SecurityValidationResultInterface The validation result with score and warnings
     */
    public function validateReturn(
        PaymentContractInterface $contract,
        array $currentContext
    ): SecurityValidationResultInterface;
}
