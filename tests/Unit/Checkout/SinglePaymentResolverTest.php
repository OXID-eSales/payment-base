<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\PaymentCandidate;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentResolverInterface;
use OxidEsales\PaymentBase\Checkout\SinglePaymentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 06 — the pure decision at the heart of "one active payment method
 * needs no selection step".
 *
 * The resolver never touches the shop: it consumes candidates already
 * extracted from OXID's filtered payment list, so the rule is testable
 * without a bootstrap and identical for every consumer (classic checkout,
 * one-page checkout, any future one).
 */
#[CoversClass(SinglePaymentResolver::class)]
final class SinglePaymentResolverTest extends TestCase
{
    private SinglePaymentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SinglePaymentResolver();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(SinglePaymentResolverInterface::class, $this->resolver);
    }

    public function testEmptyListResolvesToNull(): void
    {
        $this->assertNull($this->resolver->resolve([]));
    }

    public function testSingleCandidateResolvesToItsId(): void
    {
        $this->assertSame(
            'oxidinvoice',
            $this->resolver->resolve([new PaymentCandidate('oxidinvoice', false)])
        );
    }

    /**
     * The whole premise of the feature: two choices means the customer chooses.
     */
    public function testTwoCandidatesResolveToNull(): void
    {
        $this->assertNull($this->resolver->resolve([
            new PaymentCandidate('oxidinvoice', false),
            new PaymentCandidate('oxidcashondel', false),
        ]));
    }

    /**
     * Non-PSP proof — plain OXID core methods are treated exactly like a PSP's.
     * `oxidcashondel` (pay on arrival) has no module behind it at all.
     */
    public function testCoreOnlyPaymentMethodIsAutoAssignable(): void
    {
        $this->assertSame(
            'oxidcashondel',
            $this->resolver->resolve([new PaymentCandidate('oxidcashondel', false)])
        );
    }

    /**
     * `oxempty` is core's "no payment possible" placeholder for
     * blOtherCountryOrder shops, not a method a customer can pay with.
     */
    public function testEmptyPaymentPlaceholderIsNeverAutoAssigned(): void
    {
        $this->assertNull($this->resolver->resolve([new PaymentCandidate('oxempty', false)]));
    }

    /**
     * A method that collects data on the payment step (oxiddebitnote asks for
     * bank details) cannot be skipped — the fields live on that very page.
     */
    public function testCandidateRequiringUserInputIsNeverAutoAssigned(): void
    {
        $this->assertNull($this->resolver->resolve([new PaymentCandidate('oxiddebitnote', true)]));
    }

    /**
     * Consumers hand over OXID's payment list, which is keyed by payment id.
     * The resolver must not care whether the array is a list or keyed.
     */
    public function testKeyedArrayIsAccepted(): void
    {
        $this->assertSame(
            'oe_payments_stripe',
            $this->resolver->resolve(['oe_payments_stripe' => new PaymentCandidate('oe_payments_stripe', false)])
        );
    }
}
