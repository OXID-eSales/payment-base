<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\LateMoney;

use LogicException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGateways;
use PHPUnit\Framework\TestCase;

final class ProviderPaymentGatewaysTest extends TestCase
{
    public function testTheGatewayOfTheContractsProviderIsReturned(): void
    {
        $mollie = $this->gateway('mollie');
        $stripe = $this->gateway('stripe');

        self::assertSame($stripe, (new ProviderPaymentGateways([$mollie, $stripe]))->forContract($this->contract('stripe')));
    }

    public function testAProviderWithoutAGatewayIsAWiringErrorNamingProviderAndContract(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('paypal');
        $this->expectExceptionMessage('contract-1');

        (new ProviderPaymentGateways([$this->gateway('mollie')]))->forContract($this->contract('paypal'));
    }

    private function gateway(string $provider): ProviderPaymentGatewayInterface
    {
        $gateway = $this->createStub(ProviderPaymentGatewayInterface::class);
        $gateway->method('provider')->willReturn($provider);

        return $gateway;
    }

    private function contract(string $provider): PaymentContractInterface
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn($provider);
        $contract->method('getId')->willReturn('contract-1');

        return $contract;
    }
}
