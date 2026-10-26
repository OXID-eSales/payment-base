<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * The second-factor window the money operations demand. The admin API's own error (`step_up_required`) is
 * thrown when the admin has not proven the factor recently.
 *
 * @since 1.5.0
 */
interface AdminStepUpInterface
{
    public function require(): void;
}
