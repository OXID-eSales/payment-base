<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Admin;

use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelRenderable;
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

    public function testMergeExtendsViewDataAtRenderBoundary(): void
    {
        $renderable = new PaymentPanelRenderable('@x/y', ['a' => 1], 'stripe');
        $merged = $renderable->withExtraViewData(['b' => 2, 'a' => 10]);

        self::assertSame(['a' => 1], $renderable->viewData, 'Original DTO stays untouched');
        self::assertSame(['a' => 10, 'b' => 2], $merged->viewData);
        self::assertSame($renderable->templatePath, $merged->templatePath);
        self::assertSame($renderable->providerKey, $merged->providerKey);
    }
}
