<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\ShippingCandidate;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingResolverInterface;
use OxidEsales\PaymentBase\Checkout\SingleShippingResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 07 — the pure decision at the heart of "one active shipping method
 * needs no selector".
 *
 * The resolver never touches the shop: it consumes candidates already
 * extracted from the delivery-set list OXID has filtered for this user,
 * country and basket, so the rule is testable without a bootstrap.
 *
 * Deliberately simpler than its payment sibling. A delivery set has no
 * `OXVALDESC`, so there is no "requires user input" rule, and core has no
 * `oxempty` placeholder among delivery sets — the list is empty when nothing
 * is deliverable. Inventing either rule to make the two look symmetric would
 * be dead code.
 */
#[CoversClass(SingleShippingResolver::class)]
final class SingleShippingResolverTest extends TestCase
{
    private SingleShippingResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SingleShippingResolver();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(SingleShippingResolverInterface::class, $this->resolver);
    }

    public function testEmptyListResolvesToNull(): void
    {
        $this->assertNull($this->resolver->resolve([]));
    }

    public function testSingleCandidateResolvesToItsId(): void
    {
        $this->assertSame(
            'oxidstandard',
            $this->resolver->resolve([new ShippingCandidate('oxidstandard')])
        );
    }

    /**
     * The whole premise of the feature: two carriers means the customer
     * chooses. This is the regression net for every existing shop.
     */
    public function testTwoCandidatesResolveToNull(): void
    {
        $this->assertNull($this->resolver->resolve([
            new ShippingCandidate('oxidstandard'),
            new ShippingCandidate('express'),
        ]));
    }

    public function testThreeCandidatesResolveToNull(): void
    {
        $this->assertNull($this->resolver->resolve([
            new ShippingCandidate('oxidstandard'),
            new ShippingCandidate('express'),
            new ShippingCandidate('pickup'),
        ]));
    }

    /**
     * An id of '' names a set that does not exist. Assigning it would write a
     * falsy `sShipSet` — exactly the state this sprint exists to prevent.
     */
    public function testEmptyIdIsNeverAutoAssigned(): void
    {
        $this->assertNull($this->resolver->resolve([new ShippingCandidate('')]));
    }

    /**
     * Consumers hand over OXID's delivery-set list, which is keyed by set id.
     * The resolver must not care whether the array is a list or keyed.
     */
    public function testKeyedArrayIsAccepted(): void
    {
        $this->assertSame(
            'oxidstandard',
            $this->resolver->resolve(['oxidstandard' => new ShippingCandidate('oxidstandard')])
        );
    }
}
