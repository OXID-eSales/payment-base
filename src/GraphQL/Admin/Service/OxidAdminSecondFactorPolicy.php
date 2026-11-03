<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\GraphQL\AdminApi\Mfa\Service\MfaSettings;
use OxidEsales\PaymentBase\Payment\SecondFactorPolicyInterface;

/**
 * The admin API's MFA setting as the shop's second-factor policy. Without the admin API (`@?`) there is no second
 * factor anywhere to ask for.
 *
 * @since 1.7.0
 */
final class OxidAdminSecondFactorPolicy implements SecondFactorPolicyInterface
{
    public function __construct(private readonly ?MfaSettings $settings)
    {
    }

    public function isRequired(): bool
    {
        return $this->settings?->isRequired() ?? false;
    }
}
