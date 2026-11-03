<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

use LogicException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Hands a contract to its provider's gateway (tag `oe.payment.provider_payment_gateway`). A provider without
 * one is a wiring error, not something to work around.
 *
 * @since 1.8.0
 */
class ProviderPaymentGateways implements ProviderPaymentGatewaysInterface
{
    /**
     * @param iterable<ProviderPaymentGatewayInterface> $gateways
     */
    public function __construct(private readonly iterable $gateways)
    {
    }

    public function forContract(PaymentContractInterface $contract): ProviderPaymentGatewayInterface
    {
        $provider = (string) $contract->getProvider();
        foreach ($this->gateways as $gateway) {
            if ($gateway->provider() === $provider) {
                return $gateway;
            }
        }

        throw new LogicException(sprintf(
            'No provider payment gateway for provider "%s" (contract %s)',
            $provider,
            (string) $contract->getId()
        ));
    }
}
