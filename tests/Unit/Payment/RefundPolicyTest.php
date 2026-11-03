<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment;

use OxidEsales\PaymentBase\Payment\RefundPolicy;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use PHPUnit\Framework\TestCase;

final class RefundPolicyTest extends TestCase
{
    public function testCapturedContract_AllowsARefundUpToWhatIsLeft(): void
    {
        $contract = ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0, 30.0);

        self::assertSame(70.0, (new RefundPolicy())->remaining($contract));
        self::assertTrue((new RefundPolicy())->decide($contract, 70.0, 'EUR')->allowed);
    }

    public function testAmountAboveWhatIsLeft_IsRefused(): void
    {
        $decision = (new RefundPolicy())->decide(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0, 30.0), 70.01, 'EUR');

        self::assertSame('exceeds_refundable', $decision->code);
        self::assertSame('amount', $decision->field);
    }

    public function testFullyRefundedContract_IsNotRefundable(): void
    {
        $decision = (new RefundPolicy())->decide(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0, 100.0), 1.0, 'EUR');

        self::assertSame('not_refundable', $decision->code);
        self::assertSame('orderId', $decision->field);
    }

    public function testAuthorisedContractWithoutCapture_IsNotRefundable(): void
    {
        $decision = (new RefundPolicy())->decide(ContractFactory::authorized('order-1'), 1.0, 'EUR');

        self::assertSame('not_refundable', $decision->code);
    }

    public function testContractWithoutAReportedCapture_HasNothingToRefund(): void
    {
        $contract = ContractFactory::committedUncaptured('order-1', 'stripe', 100.0);

        self::assertSame(0.0, (new RefundPolicy())->remaining($contract));
        self::assertSame('not_refundable', (new RefundPolicy())->decide($contract, 1.0, 'EUR')->code);
    }

    public function testUnusableAmount_IsRefused(): void
    {
        $decision = (new RefundPolicy())->decide(ContractFactory::fulfilled('order-1'), 0.0, 'EUR');

        self::assertSame('amount_invalid', $decision->code);
    }

    public function testOtherCurrencyThanTheContracts_IsRefused(): void
    {
        $decision = (new RefundPolicy())->decide(ContractFactory::fulfilled('order-1'), 5.0, 'USD');

        self::assertSame('currency_mismatch', $decision->code);
    }
}
