<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\ShippingCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 07 — one delivery set, reduced to the single fact the decision needs.
 */
#[CoversClass(ShippingCandidate::class)]
final class ShippingCandidateTest extends TestCase
{
    public function testCarriesItsId(): void
    {
        $this->assertSame('oxidstandard', (new ShippingCandidate('oxidstandard'))->getId());
    }

    public function testAcceptsAnEmptyIdSoTheResolverCanRejectIt(): void
    {
        $this->assertSame('', (new ShippingCandidate(''))->getId());
    }
}
