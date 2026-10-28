<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;

/**
 * The classic admin's own error display (the red message above the tab), unless the provider already recorded its
 * own classic feedback.
 *
 * OXID's Registry and UtilsView are the classic admin's only channel: the shop offers no injectable one (framework
 * constraint, like the other `Registry::get*` uses of the admin controller).
 *
 * @since 1.7.0
 */
final class OxidAdminRefusalFeedback implements AdminRefusalFeedbackInterface
{
    public function show(AdminActionRefusedException $refusal): void
    {
        if ($refusal->feedbackRecorded()) {
            return;
        }
        Registry::getUtilsView()->addErrorToDisplay($refusal->getMessage());
    }
}
