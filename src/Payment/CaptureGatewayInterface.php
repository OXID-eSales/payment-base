<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;

/**
 * Captures an authorisation at the PSP. The narrow port a provider module implements: it talks to the PSP and nothing else. The
 * {@see MoneyOperationRunner} checks the rules before and books what comes back after.
 *
 * @since 1.7.0
 */
interface CaptureGatewayInterface extends ProviderGatewayInterface
{
    /**
     * @throws AdminActionRefusedException the PSP refused for a reason the admin should read
     */
    public function capture(CaptureRequest $request): CaptureResult;
}
