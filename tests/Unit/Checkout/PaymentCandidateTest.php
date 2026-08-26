<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\PaymentCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentCandidate::class)]
final class PaymentCandidateTest extends TestCase
{
    public function testCarriesIdAndInputRequirement(): void
    {
        $candidate = new PaymentCandidate('oxidinvoice', true);

        $this->assertSame('oxidinvoice', $candidate->getId());
        $this->assertTrue($candidate->requiresUserInput());
    }

    public function testInputRequirementDefaultsToFalse(): void
    {
        $this->assertFalse((new PaymentCandidate('oxidinvoice'))->requiresUserInput());
    }
}
