<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stands in for the order controller. Only the single-payment answer is real;
 * every other view call the page makes is absorbed, which is enough to render
 * the checkout summary.
 */
class SinglePaymentProbeView
{
    public function __construct(private readonly bool $autoAssigned)
    {
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return $this->autoAssigned;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * A basket with contents — without it the order page renders its
 * "basket is empty" branch and never reaches the payment block.
 */
class SinglePaymentProbeBasket
{
    public function getProductsCount(): int
    {
        return 3;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Sprint 06 — the order page's payment block.
 *
 * payment-base overrides one block of the shop's order template. Whether that
 * override actually lands is a property of the shop's template chain, not of
 * the file: three other modules extend the same template, and the theme-folder
 * convention it relies on is easy to get subtly wrong. So this renders the real
 * template through the shop's own renderer and looks at the result.
 */
#[Group('integration')]
class SinglePaymentOrderTemplateTest extends IntegrationTestCase
{
    /** The core payment block's form — present iff the block renders. */
    private const PAYMENT_BLOCK_MARKER = 'orderPayment';

    /** The sibling shipping block, which must never be affected. */
    private const SHIPPING_BLOCK_MARKER = 'orderShipping';

    public function testPaymentBlockRendersWhenTheCustomerHadAChoice(): void
    {
        $output = $this->renderOrderPage(autoAssigned: false);

        $this->assertStringContainsString(self::PAYMENT_BLOCK_MARKER, $output);
    }

    public function testPaymentBlockIsLeftOutForASinglePaymentMethod(): void
    {
        $output = $this->renderOrderPage(autoAssigned: true);

        $this->assertStringNotContainsString(self::PAYMENT_BLOCK_MARKER, $output);
    }

    public function testShippingBlockSurvivesEitherWay(): void
    {
        $this->assertStringContainsString(
            self::SHIPPING_BLOCK_MARKER,
            $this->renderOrderPage(autoAssigned: true)
        );
        $this->assertStringContainsString(
            self::SHIPPING_BLOCK_MARKER,
            $this->renderOrderPage(autoAssigned: false)
        );
    }

    private function renderOrderPage(bool $autoAssigned): string
    {
        $bridge = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class);

        return $bridge->getTemplateRenderer()->renderTemplate(
            'page/checkout/order.html.twig',
            [
                'oView' => new SinglePaymentProbeView($autoAssigned),
                'oxcmp_basket' => new SinglePaymentProbeBasket(),
            ]
        );
    }
}
