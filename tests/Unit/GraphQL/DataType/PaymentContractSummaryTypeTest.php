<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\DataType;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractSummary;
use OxidEsales\PaymentBase\GraphQL\DataType\PaymentContractSummaryType;
use PHPUnit\Framework\TestCase;

final class PaymentContractSummaryTypeTest extends TestCase
{
    public function testFields_AnySummary_ExposeExactlyTheFourValues(): void
    {
        $updatedAt = new DateTimeImmutable('2026-10-22 10:00:00');

        $type = new PaymentContractSummaryType(new PaymentContractSummary('c-1', 'fulfilled', 'mollie', $updatedAt));

        self::assertSame('c-1', $type->contractId());
        self::assertSame('fulfilled', $type->state());
        self::assertSame('mollie', $type->provider());
        self::assertSame($updatedAt, $type->updatedAt());
    }
}
