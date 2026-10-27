<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use LogicException;
use OxidEsales\GraphQL\Admin\Mfa\Service\StepUp;

/**
 * The admin module's StepUp as the money operations' second-factor gate. The module is optional: the service is
 * wired with `@?`, and without it the gate fails closed (the operations only exist when the admin API does).
 *
 * @since 1.5.0
 */
final class OxidAdminStepUp implements AdminStepUpInterface
{
    public function __construct(private readonly ?StepUp $stepUp)
    {
    }

    public function require(): void
    {
        if ($this->stepUp === null) {
            throw new LogicException('The admin API is not installed: there is no step-up to ask');
        }
        $this->stepUp->require(StepUp::FIVE_MINUTES);
    }
}
