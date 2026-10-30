<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * CFG-1: payment module settings change in the classic admin only, so payment-base carries no support for a GraphQL
 * write of them: no superadmin gate for secrets, and ADMIN_PAYMENT_CONFIG says it is for reading.
 */
final class NoSettingsWriteSupportTest extends TestCase
{
    public function testThereIsNoSuperadminCheckForSecretWrites(): void
    {
        self::assertFalse(interface_exists('OxidEsales\\PaymentBase\\GraphQL\\Admin\\Service\\AdminSuperadminCheckInterface'));
        self::assertFalse(class_exists('OxidEsales\\PaymentBase\\GraphQL\\Admin\\Service\\OxidAdminSuperadminCheck'));
        $services = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
        foreach (array_keys($services) as $id) {
            self::assertStringNotContainsString('Superadmin', (string) $id);
        }
    }

    public function testTheConfigRightIsDescribedAsReadOnly(): void
    {
        foreach ((new PaymentRightsProvider())->rights() as $right) {
            if ($right->right() !== PaymentRightsProvider::CONFIG) {
                continue;
            }
            self::assertStringNotContainsString('Change', $right->description());
            self::assertStringContainsStringIgnoringCase('read-only', $right->description());

            return;
        }
        self::fail('ADMIN_PAYMENT_CONFIG is not provided');
    }
}
