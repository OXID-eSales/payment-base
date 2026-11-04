<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use PHPUnit\Framework\TestCase;

final class PaymentPanelRenderableTest extends TestCase
{
    public function testHoldsTemplatePathAndViewData(): void
    {
        $renderable = new PaymentPanelRenderable(
            templatePath: '@oe_payments_stripe_wallet/admin/panel/stripe',
            viewData: ['contractId' => 'c_1', 'amount' => 42.0],
            providerKey: 'stripe',
        );

        self::assertSame('@oe_payments_stripe_wallet/admin/panel/stripe', $renderable->templatePath);
        self::assertSame(['contractId' => 'c_1', 'amount' => 42.0], $renderable->viewData);
        self::assertSame('stripe', $renderable->providerKey);
    }
}
