<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment;

use LogicException;
use OxidEsales\PaymentBase\Payment\CancelGatewayInterface;
use OxidEsales\PaymentBase\Payment\CancelRequest;
use OxidEsales\PaymentBase\Payment\CaptureGatewayInterface;
use OxidEsales\PaymentBase\Payment\CaptureRequest;
use OxidEsales\PaymentBase\Payment\CaptureResult;
use OxidEsales\PaymentBase\Payment\ProviderGatewayRegistry;
use OxidEsales\PaymentBase\Payment\RefundGatewayInterface;
use OxidEsales\PaymentBase\Payment\RefundRequest;
use OxidEsales\PaymentBase\Payment\RefundResult;
use PHPUnit\Framework\TestCase;

final class ProviderGatewayRegistryTest extends TestCase
{
    public function testFindsTheGatewayOfAKindByProvider(): void
    {
        $paypal = $this->captureGateway('paypal');
        $registry = new ProviderGatewayRegistry([$this->captureGateway('mollie'), $paypal]);

        self::assertSame($paypal, $registry->captureFor('paypal'));
        self::assertNull($registry->refundFor('paypal'));
        self::assertNull($registry->cancelFor('paypal'));
        self::assertNull($registry->captureFor('stripe'));
    }

    public function testOneGatewayThatImplementsAllThreePortsServesAllThree(): void
    {
        $gateway = new class () implements CaptureGatewayInterface, RefundGatewayInterface, CancelGatewayInterface {
            public function providerName(): string
            {
                return 'stripe';
            }

            public function capture(CaptureRequest $request): CaptureResult
            {
                return new CaptureResult('cap-1', $request->amount);
            }

            public function refund(RefundRequest $request): RefundResult
            {
                return new RefundResult('re-1', $request->amount, RefundResult::SUCCEEDED);
            }

            public function cancel(CancelRequest $request): void
            {
            }
        };
        $registry = new ProviderGatewayRegistry([$gateway]);

        self::assertSame($gateway, $registry->captureFor('stripe'));
        self::assertSame($gateway, $registry->refundFor('stripe'));
        self::assertSame($gateway, $registry->cancelFor('stripe'));
    }

    public function testTwoGatewaysOfTheSameKindForOneProvider_AreAWiringError(): void
    {
        $this->expectException(LogicException::class);

        new ProviderGatewayRegistry([$this->captureGateway('paypal'), $this->captureGateway('paypal')]);
    }

    public function testResultsRefuseWhatCannotBeBooked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RefundResult('re-1', 5.0, 'unknown');
    }

    private function captureGateway(string $provider): CaptureGatewayInterface
    {
        return new class ($provider) implements CaptureGatewayInterface {
            public function __construct(private readonly string $provider)
            {
            }

            public function providerName(): string
            {
                return $this->provider;
            }

            public function capture(CaptureRequest $request): CaptureResult
            {
                return new CaptureResult('cap-1', $request->amount);
            }
        };
    }
}
