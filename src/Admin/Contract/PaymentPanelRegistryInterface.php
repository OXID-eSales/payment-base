<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Admin\Contract;

use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelContext;

interface PaymentPanelRegistryInterface
{
    public function resolveFor(PaymentPanelContext $context): ?PaymentPanelProviderInterface;

    public function resolveByProviderName(string $providerName): ?PaymentPanelProviderInterface;
}
