<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Service;

use OxidEsales\PaymentBase\GraphQL\Service\PermissionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S6 — the right a provider's checkout mutations demand
 * (`#[Right('PAYMENT_CHECKOUT')]`). Granted to the same groups the storefront
 * grants PLACE_ORDER to; anonymous shoppers are phase 4.
 */
final class PermissionProviderTest extends TestCase
{
    public function testGrantsPaymentCheckoutToCustomersAndNotYetOrderedButNotToAnonymous(): void
    {
        $permissions = (new PermissionProvider())->getPermissions();

        foreach (['oxidcustomer', 'oxidnotyetordered', 'oxidadmin'] as $group) {
            self::assertContains(PermissionProvider::PAYMENT_CHECKOUT, $permissions[$group], $group);
        }
        self::assertNotContains(PermissionProvider::PAYMENT_CHECKOUT, $permissions['oxidanonymous'] ?? []);
        self::assertSame('PAYMENT_CHECKOUT', PermissionProvider::PAYMENT_CHECKOUT);
    }

    /**
     * The sealed client config is read by the storefront before any login, with
     * a guest's anonymous token (group oxidanonymous), so that group gets it too.
     */
    public function testGrantsPaymentClientConfigToEveryoneHoldingAToken(): void
    {
        $permissions = (new PermissionProvider())->getPermissions();

        foreach (['oxidanonymous', 'oxidcustomer', 'oxidnotyetordered', 'oxidadmin'] as $group) {
            self::assertContains(PermissionProvider::PAYMENT_CLIENT_CONFIG, $permissions[$group], $group);
        }
        self::assertSame('PAYMENT_CLIENT_CONFIG', PermissionProvider::PAYMENT_CLIENT_CONFIG);
    }
}
