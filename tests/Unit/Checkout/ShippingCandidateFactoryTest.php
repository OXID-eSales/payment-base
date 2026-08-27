<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\ShippingCandidateFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A delivery set as OXID's list carries it. `blSelected` is a public property
 * core sets on the active one; the factory has no use for it, which is the
 * point of asserting nothing about it here.
 */
final class ListedDeliverySet
{
    public bool $blSelected = false;

    public function __construct(private readonly mixed $id = null)
    {
    }

    public function getId(): mixed
    {
        if ($this->id === 'throw') {
            throw new RuntimeException('delivery set unavailable');
        }

        return $this->id;
    }
}

/**
 * Sprint 07 — turning OXID's delivery-set list into candidates.
 *
 * This is the only place that interrogates the shop's delivery-set models, so
 * it is the only place that has to survive being handed one that answers
 * differently. A checkout must never break over a carrier it merely failed to
 * interrogate.
 */
#[CoversClass(ShippingCandidateFactory::class)]
final class ShippingCandidateFactoryTest extends TestCase
{
    public function testEmptyListProducesNoCandidates(): void
    {
        $this->assertSame([], ShippingCandidateFactory::fromDeliverySetList([]));
    }

    /**
     * The array key is the delivery-set id in every core list.
     */
    public function testStringKeyIsTheId(): void
    {
        $candidates = ShippingCandidateFactory::fromDeliverySetList([
            'oxidstandard' => new ListedDeliverySet(null),
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('oxidstandard', $candidates[0]->getId());
    }

    /**
     * A numerically indexed array carries no id, so the model is asked for its
     * own — an id of "0" would name a set that does not exist.
     */
    public function testNumericKeyFallsBackToTheModelId(): void
    {
        $candidates = ShippingCandidateFactory::fromDeliverySetList([
            new ListedDeliverySet('express'),
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('express', $candidates[0]->getId());
    }

    public function testNumericKeyWithoutAModelIdIsSkipped(): void
    {
        $this->assertSame([], ShippingCandidateFactory::fromDeliverySetList([
            new ListedDeliverySet(null),
        ]));
    }

    public function testNumericKeyWithAnEmptyModelIdIsSkipped(): void
    {
        $this->assertSame([], ShippingCandidateFactory::fromDeliverySetList([
            new ListedDeliverySet(''),
        ]));
    }

    public function testNonStringModelIdIsSkipped(): void
    {
        $this->assertSame([], ShippingCandidateFactory::fromDeliverySetList([
            new ListedDeliverySet(42),
        ]));
    }

    /**
     * A foreign module's model may throw on any question. That must cost us the
     * candidate, not the checkout.
     */
    public function testThrowingModelIsSkippedNotFatal(): void
    {
        $this->assertSame([], ShippingCandidateFactory::fromDeliverySetList([
            new ListedDeliverySet('throw'),
        ]));
    }

    /**
     * A throwing model must not take its well-behaved neighbours with it —
     * and two survivors still mean "the customer chooses".
     */
    public function testAThrowingModelDoesNotHideItsNeighbours(): void
    {
        $candidates = ShippingCandidateFactory::fromDeliverySetList([
            new ListedDeliverySet('throw'),
            'oxidstandard' => new ListedDeliverySet(null),
            'express' => new ListedDeliverySet(null),
        ]);

        $this->assertSame(
            ['oxidstandard', 'express'],
            array_map(static fn ($candidate) => $candidate->getId(), $candidates)
        );
    }

    public function testAllSetsBecomeCandidates(): void
    {
        $candidates = ShippingCandidateFactory::fromDeliverySetList([
            'oxidstandard' => new ListedDeliverySet(null),
            'express' => new ListedDeliverySet(null),
        ]);

        $this->assertCount(2, $candidates);
    }
}
