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
 * Stands in for the order controller, with both single-* answers real and
 * independently settable.
 */
class SingleShippingProbeView
{
    public function __construct(
        private readonly bool $shippingAutoAssigned,
        private readonly bool $paymentAutoAssigned = false,
    ) {
    }

    public function isSingleShippingAutoAssigned(): bool
    {
        return $this->shippingAutoAssigned;
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return $this->paymentAutoAssigned;
    }

    /**
     * What core's `checkout_order_shipping_carrier_desc` prints. Without it the
     * probe answered null through __call and the carrier line was empty, which
     * would let "the name is shown" pass on an empty string.
     */
    public function getShipSet(): object
    {
        return new SinglePaymentStepProbeShipSet();
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Sprint 07 — the order page's shipping-carrier block.
 *
 * Renders the real template through the shop's own renderer, because whether
 * the override lands is a property of the template chain rather than of the
 * file — four modules extend this template.
 */
#[Group('integration')]
class SingleShippingOrderTemplateTest extends IntegrationTestCase
{
    /** The core carrier block's form — present iff the block renders. */
    private const SHIPPING_BLOCK_MARKER = 'orderShipping';

    /** The sibling payment block, decided separately. */
    private const PAYMENT_BLOCK_MARKER = 'orderPayment';

    public function testCarrierBlockRendersWhenTheCustomerHadAChoice(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: false);

        $this->assertStringContainsString(self::SHIPPING_BLOCK_MARKER, $output);
    }

    /**
     * Revised 2026-08-31: the carrier is named, only the pencil goes. The order
     * page is what the customer confirms; dropping the whole block left it
     * silent about how the goods were coming.
     */
    public function testCarrierIsNamedWithoutAnEditControlForASingleDeliverySet(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: true);

        // Shown...
        $this->assertStringContainsString(SinglePaymentStepProbeShipSet::TITLE, $output);
        // ...but not changeable: the edit form that posts back to cl=payment is gone.
        $this->assertStringNotContainsString(self::SHIPPING_BLOCK_MARKER, $output);
        // Paired positive: the page really rendered.
        $this->assertStringContainsString(self::PAYMENT_BLOCK_MARKER, $output);
    }

    /**
     * The two blocks are siblings and are decided independently — neither may
     * drag the other out of the page.
     */
    public function testPaymentBlockSurvivesWhenOnlyShippingIsAutoAssigned(): void
    {
        $this->assertStringContainsString(
            self::PAYMENT_BLOCK_MARKER,
            $this->renderOrderPage(shippingAutoAssigned: true)
        );
    }

    public function testCarrierBlockSurvivesWhenOnlyPaymentIsAutoAssigned(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: false, paymentAutoAssigned: true);

        $this->assertStringContainsString(self::SHIPPING_BLOCK_MARKER, $output);
        $this->assertStringNotContainsString(self::PAYMENT_BLOCK_MARKER, $output);
    }

    /**
     * Both shortcuts at once. The payment block still goes entirely — there is
     * no equivalent "show it anyway" requirement for it — while shipping keeps
     * its name and loses only the pencil.
     */
    public function testCarrierIsStillNamedWhenBothAreAutoAssigned(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: true, paymentAutoAssigned: true);

        $this->assertStringContainsString(SinglePaymentStepProbeShipSet::TITLE, $output);
        $this->assertStringNotContainsString(self::SHIPPING_BLOCK_MARKER, $output);
        $this->assertStringNotContainsString(self::PAYMENT_BLOCK_MARKER, $output);
        // Paired positive: the order page still renders its confirm step.
        $this->assertStringContainsString('checkout', $output);
    }

    private function renderOrderPage(bool $shippingAutoAssigned, bool $paymentAutoAssigned = false): string
    {
        $bridge = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class);

        $output = $bridge->getTemplateRenderer()->renderTemplate(
            'page/checkout/order.html.twig',
            [
                'oView' => new SingleShippingProbeView($shippingAutoAssigned, $paymentAutoAssigned),
                'oxcmp_basket' => new SinglePaymentProbeBasket(),
            ]
        );

        // A shop without a usable frontend theme answers with the template
        // name instead of markup. Say so plainly — a silent pass here would
        // claim the override was verified when nothing was rendered.
        $this->assertNotSame(
            'page/checkout/order.html.twig',
            trim($output),
            'the shop renderer returned the template name — no frontend theme in this environment'
        );

        return $output;
    }
}
