<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Fixture;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminActionOptionsResolverInterface;

/** A provider that declares no reasons or description: free text, as the reader answers it. */
final class FreeTextActionOptions implements AdminActionOptionsResolverInterface
{
    public function optionsFor(PaymentContractInterface $contract): AdminActionOptions
    {
        return AdminActionOptions::freeText();
    }
}
