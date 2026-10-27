<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\GraphQL\Admin\Authorization\DataType\RightDefinition;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use PHPUnit\Framework\TestCase;

final class PaymentRightsProviderTest extends TestCase
{
    public function testRights_ReturnsTheFourPaymentRightsInTheCatalogueShape(): void
    {
        $rights = (new PaymentRightsProvider())->rights();

        self::assertContainsOnlyInstancesOf(RightDefinition::class, $rights);
        self::assertSame(
            ['ADMIN_PAYMENT_VIEW', 'ADMIN_PAYMENT_CAPTURE', 'ADMIN_PAYMENT_REFUND', 'ADMIN_PAYMENT_CONFIG'],
            array_map(static fn (RightDefinition $right): string => $right->right(), $rights),
        );
    }

    public function testRights_EveryRightBelongsToThePaymentDomainAndSaysWhatItGrants(): void
    {
        foreach ((new PaymentRightsProvider())->rights() as $right) {
            self::assertSame('payment', $right->domain());
            self::assertNotSame('', trim($right->description()));
        }
    }

    public function testRights_DoesNotImplementTheOptionalAdminInterface(): void
    {
        // The container reflects this class at module activation; an interface of an absent package is fatal.
        self::assertSame([], (new \ReflectionClass(PaymentRightsProvider::class))->getInterfaceNames());
    }
}
