<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * @since 1.8.0
 */
interface ProviderPaymentGatewaysInterface
{
    /**
     * @throws \LogicException no gateway is tagged for the contract's provider (wiring error)
     */
    public function forContract(PaymentContractInterface $contract): ProviderPaymentGatewayInterface;
}
