<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment;

use OxidEsales\PaymentBase\Payment\CapturePolicy;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapturePolicyTest extends TestCase
{
    public function testAuthorisedContract_AllowsTheFullAmountAndAPartOfIt(): void
    {
        $contract = ContractFactory::authorized('order-1', 'paypal', 100.0);

        self::assertTrue((new CapturePolicy())->decide($contract, 100.0, 'EUR')->allowed);
        self::assertTrue((new CapturePolicy())->decide($contract, 40.0, 'EUR')->allowed);
    }

    public function testRemaining_IsTheAuthorisedAmountLessWhatWasCaptured(): void
    {
        self::assertSame(100.0, (new CapturePolicy())->remaining(ContractFactory::authorized('order-1', 'paypal', 100.0)));
    }

    public function testPendingContract_IsNotCapturable(): void
    {
        $decision = (new CapturePolicy())->decide(ContractFactory::pending('order-1'), 10.0, 'EUR');

        self::assertFalse($decision->allowed);
        self::assertSame('not_capturable', $decision->code);
        self::assertSame('orderId', $decision->field);
    }

    public function testFullyCapturedContract_IsNotCapturable(): void
    {
        $decision = (new CapturePolicy())->decide(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0), 1.0, 'EUR');

        self::assertSame('not_capturable', $decision->code);
    }

    public function testAmountAboveTheRemainder_IsRefused(): void
    {
        $decision = (new CapturePolicy())->decide(ContractFactory::authorized('order-1', 'paypal', 100.0), 100.01, 'EUR');

        self::assertSame('exceeds_capturable', $decision->code);
        self::assertSame('amount', $decision->field);
    }

    public function testAmountWithinTheHalfCentTolerance_IsAllowed(): void
    {
        self::assertTrue((new CapturePolicy())->decide(ContractFactory::authorized('order-1', 'paypal', 100.0), 100.004, 'EUR')->allowed);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function unusableAmounts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-5.0];
        yield 'nan' => [NAN];
        yield 'infinite' => [INF];
        yield 'below the minor unit' => [0.004];
    }

    #[DataProvider('unusableAmounts')]
    public function testUnusableAmount_IsRefused(float $amount): void
    {
        $decision = (new CapturePolicy())->decide(ContractFactory::authorized('order-1', 'paypal', 100.0), $amount, 'EUR');

        self::assertSame('amount_invalid', $decision->code);
        self::assertSame('amount', $decision->field);
    }

    public function testOtherCurrencyThanTheContracts_IsRefused(): void
    {
        $decision = (new CapturePolicy())->decide(ContractFactory::authorized('order-1', 'paypal', 100.0), 10.0, 'USD');

        self::assertSame('currency_mismatch', $decision->code);
        self::assertSame('currency', $decision->field);
    }
}
