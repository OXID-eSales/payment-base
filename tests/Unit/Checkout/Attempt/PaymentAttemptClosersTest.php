<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Attempt;

use LogicException;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptCloserInterface;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptClosers;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2d — each provider closes its own payment page. A provider
 * without a closer is a wiring error, not "nothing to close".
 */
final class PaymentAttemptClosersTest extends TestCase
{
    public function testClose_AsksTheCloserOfTheContractsProvider(): void
    {
        $closers = new PaymentAttemptClosers([
            $this->closer('mollie', AttemptClosure::Open),
            $this->closer('stripe', AttemptClosure::Closed),
        ]);

        self::assertSame(AttemptClosure::Closed, $closers->close($this->contract('stripe')));
    }

    public function testClose_WithoutACloserForTheProvider_Fails(): void
    {
        $closers = new PaymentAttemptClosers([$this->closer('mollie', AttemptClosure::Closed)]);

        $this->expectException(LogicException::class);
        $closers->close($this->contract('paypal'));
    }

    private function closer(string $provider, AttemptClosure $answer): PaymentAttemptCloserInterface
    {
        return new class ($provider, $answer) implements PaymentAttemptCloserInterface {
            public function __construct(private readonly string $provider, private readonly AttemptClosure $answer)
            {
            }

            public function provider(): string
            {
                return $this->provider;
            }

            public function close(PaymentContractInterface $contract): AttemptClosure
            {
                return $this->answer;
            }
        };
    }

    private function contract(string $provider): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 1.0, 'totalNet' => 1.0, 'totalVat' => 0.0, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->setProvider($provider, 'id-1');

        return $contract;
    }
}
