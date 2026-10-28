<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\LazyActionBounds;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentContractDetailsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, float, float, float, float}>
     */
    public static function amountsByState(): iterable
    {
        yield 'authorised, nothing captured' => ['authorized', 0.0, 0.0, 100.0, 0.0];
        yield 'committed, nothing captured (manual capture provider)' => ['committed', 0.0, 0.0, 100.0, 0.0];
        yield 'pending is not capturable' => ['pending', 0.0, 0.0, 0.0, 0.0];
        yield 'fulfilled, captured in full' => ['fulfilled', 100.0, 0.0, 0.0, 100.0];
        yield 'fulfilled, partly refunded' => ['fulfilled', 100.0, 30.0, 0.0, 70.0];
        yield 'fully refunded' => ['fulfilled', 100.0, 100.0, 0.0, 0.0];
        yield 'refund noise below half a cent is no refundable rest' => ['fulfilled', 100.0, 99.996, 0.0, 0.0];
        yield 'cancelled contract offers nothing' => ['cancelled', 0.0, 0.0, 0.0, 0.0];
        yield 'partly captured authorisation offers no further capture' => ['committed', 40.0, 0.0, 0.0, 40.0];
    }

    #[DataProvider('amountsByState')]
    public function testBounds_FollowStateAndRecordedAmounts(
        string $state,
        float $captured,
        float $refunded,
        float $expectedCapturable,
        float $expectedRefundable,
    ): void {
        $details = $this->details($state, $captured, $refunded);

        self::assertEqualsWithDelta($expectedCapturable, $details->amountCapturable(), 0.0001);
        self::assertEqualsWithDelta($expectedRefundable, $details->amountRefundable(), 0.0001);
    }

    public function testProviderBounds_ReplaceTheLocalNumbers_WhereTheProviderAnswers(): void
    {
        $multiCapture = $this->withBounds('fulfilled', 30.0, 0.0, new LazyActionBounds(static fn (): AdminActionBounds => new AdminActionBounds(70.0, 12.0)));

        self::assertSame(70.0, $multiCapture->amountCapturable(), 'the remainder Mollie still holds, though the shop recorded a capture');
        self::assertSame(12.0, $multiCapture->amountRefundable());
        self::assertFalse($multiCapture->providerBoundsUnavailable());
    }

    public function testProviderBounds_OmittedSide_KeepsThePaymentBaseNumber(): void
    {
        $details = $this->withBounds('fulfilled', 100.0, 30.0, new LazyActionBounds(static fn (): AdminActionBounds => new AdminActionBounds(refundable: 20.0)));

        self::assertSame(0.0, $details->amountCapturable());
        self::assertSame(20.0, $details->amountRefundable());
    }

    public function testProviderBounds_NoOpinion_KeepsTheLocalNumbers(): void
    {
        $details = $this->withBounds('authorized', 0.0, 0.0, new LazyActionBounds(static fn (): ?AdminActionBounds => null));

        self::assertSame(100.0, $details->amountCapturable());
    }

    public function testProviderBounds_Unreadable_FailClosed_AndAreReportedAsUnavailable(): void
    {
        $details = $this->withBounds('fulfilled', 100.0, 0.0, new LazyActionBounds(static fn (): AdminActionBounds => AdminActionBounds::unavailable()));

        self::assertSame([0.0, 0.0], [$details->amountCapturable(), $details->amountRefundable()]);
        self::assertTrue($details->providerBoundsUnavailable());
    }

    public function testProviderBounds_AreAskedOnce_NoMatterHowOftenTheyAreRead(): void
    {
        $asked = 0;
        $details = $this->withBounds('fulfilled', 100.0, 0.0, new LazyActionBounds(static function () use (&$asked): AdminActionBounds {
            ++$asked;

            return new AdminActionBounds(1.0, 2.0);
        }));

        $details->amountCapturable();
        $details->amountRefundable();
        $details->providerBoundsUnavailable();

        self::assertSame(1, $asked);
    }

    public function testWithoutProviderBounds_NothingIsAsked_AndTheNumbersAreToday(): void
    {
        self::assertFalse($this->withBounds('authorized', 0.0, 0.0, null)->providerBoundsUnavailable());
    }

    private function withBounds(string $state, float $captured, float $refunded, ?LazyActionBounds $bounds): PaymentContractDetails
    {
        return new PaymentContractDetails('c', 'mollie', $state, 100.0, $captured, $refunded, 'EUR', [], null, $bounds);
    }

    public function testFingerprint_ChangesWithStateAndAmounts_NotWithTransactionsOnly(): void
    {
        $base = $this->details('authorized', 0.0, 0.0)->fingerprint();

        self::assertSame($base, $this->details('authorized', 0.0, 0.0)->fingerprint());
        self::assertNotSame($base, $this->details('fulfilled', 100.0, 0.0)->fingerprint());
        self::assertNotSame(
            $this->details('fulfilled', 100.0, 0.0)->fingerprint(),
            $this->details('fulfilled', 100.0, 10.0)->fingerprint(),
        );
    }

    private function details(string $state, float $captured, float $refunded): PaymentContractDetails
    {
        return new PaymentContractDetails('c-1', 'paypal', $state, 100.0, $captured, $refunded, 'EUR', []);
    }
}
