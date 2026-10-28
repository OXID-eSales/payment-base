<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * @since 1.7.0
 */
interface AdminActionOptionsResolverInterface
{
    /** What the contract's provider declares; free text when it declares nothing or cannot answer. */
    public function optionsFor(PaymentContractInterface $contract): AdminActionOptions;
}
