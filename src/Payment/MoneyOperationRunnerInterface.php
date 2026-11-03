<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;

/**
 * The one admin money path: the classic admin and the admin API both end here.
 *
 * @since 1.7.0
 */
interface MoneyOperationRunnerInterface
{
    /**
     * @throws AdminAccessDeniedException the admin may not do this; the attempt is audited
     */
    public function run(AdminPaymentOperation $operation, AdminChannelInterface $channel): PaymentOperationResult;
}
