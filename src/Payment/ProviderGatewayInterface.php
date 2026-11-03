<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

/**
 * What every provider gateway (capture, refund, cancel against the PSP) has in common: whose it is. Provider modules
 * tag their gateways `oe.payment.gateway`; the {@see ProviderGatewayRegistryInterface} finds them by the provider name
 * the contract carries.
 *
 * @since 1.7.0
 */
interface ProviderGatewayInterface
{
    /** The provider key of the contract (`PaymentContractInterface::getProvider()`). */
    public function providerName(): string;
}
