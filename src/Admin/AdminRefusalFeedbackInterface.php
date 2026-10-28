<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;

/**
 * Where the classic Payment tab shows a provider's {@see AdminActionRefusedException}.
 *
 * @since 1.7.0
 */
interface AdminRefusalFeedbackInterface
{
    public function show(AdminActionRefusedException $refusal): void;
}
