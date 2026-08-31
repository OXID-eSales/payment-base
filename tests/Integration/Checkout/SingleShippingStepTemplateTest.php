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
 * Stands in for the payment controller. Both single-* answers are real and
 * independently settable, so the two blocks can be checked for interference.
 */
class SingleShippingStepProbeView
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

    public function getSingleShippingId(): string
    {
        return $this->shippingAutoAssigned ? 'oxidstandard' : '';
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return $this->paymentAutoAssigned;
    }

    public function getSinglePaymentId(): string
    {
        return $this->paymentAutoAssigned ? 'oxidinvoice' : '';
    }

    /** @return array<string, mixed> */
    public function getPaymentList(): array
    {
        return ['oxidinvoice' => new SinglePaymentStepProbePayment()];
    }

    public function getCheckedPaymentId(): string
    {
        return 'oxidinvoice';
    }

    /** @return array<string, mixed> */
    public function getAllSets(): array
    {
        return ['oxidstandard' => new SinglePaymentStepProbeShipSet()];
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Sprint 07 — the payment step's delivery-set selector.
 *
 * payment-base overrides one more block of the shop's payment template.
 * Whether that override lands is a property of the shop's template chain, not
 * of the file, so this renders the real template through the shop's own
 * renderer and looks at the result.
 *
 * Every "absent" assertion is paired with a "present" one: a page that failed
 * to render satisfies `assertStringNotContainsString` perfectly.
 */
#[Group('integration')]
class SingleShippingStepTemplateTest extends IntegrationTestCase
{
    /** The delivery-set dropdown — present iff the block renders. */
    private const SHIPPING_SELECT_MARKER = 'name="sShipSet"';

    public function testSelectorIsVisibleWhenTheCustomerHasAChoice(): void
    {
        $output = $this->renderPaymentStep(shippingAutoAssigned: false);

        $this->assertStringContainsString(self::SHIPPING_SELECT_MARKER, $output);
    }

    /**
     * Revised 2026-08-31: the carrier is shown, it just cannot be changed.
     * Hiding the block outright also hid *which* carrier the customer was
     * getting, on the last page where they could still have questioned it.
     */
    public function testCarrierIsNamedButNotSelectableForASingleDeliverySet(): void
    {
        $output = $this->renderPaymentStep(shippingAutoAssigned: true);

        // Shown...
        $this->assertStringContainsString(SinglePaymentStepProbeShipSet::TITLE, $output);
        $this->assertStringContainsString('id="singleShippingMethod"', $output);
        // ...but not changeable: no dropdown, and no noscript submit either.
        $this->assertStringNotContainsString(self::SHIPPING_SELECT_MARKER, $output);
        $this->assertStringNotContainsString('UPDATE_SHIPPING_CARRIER', $output);
        // Paired positive: the step really rendered.
        $this->assertStringContainsString('id="payment"', $output);
    }

    /**
     * The payment block is a sibling. Hiding the carrier must not take the
     * customer's payment choice with it.
     */
    public function testPaymentSelectionSurvivesWhenOnlyShippingIsAutoAssigned(): void
    {
        $output = $this->renderPaymentStep(shippingAutoAssigned: true);

        $this->assertStringContainsString('type="radio"', $output);
        $this->assertStringContainsString('name="paymentid"', $output);
    }

    /**
     * Both shortcuts at once — the state sprint 07 §7 D1 is about. The step is
     * reduced to the bare form, and that form must survive: the "next" button
     * sits outside it and submits it by id.
     */
    public function testBothBlocksCanBeHiddenAndTheSubmitPathSurvives(): void
    {
        $output = $this->renderPaymentStep(shippingAutoAssigned: true, paymentAutoAssigned: true);

        $this->assertStringNotContainsString(self::SHIPPING_SELECT_MARKER, $output);
        $this->assertStringNotContainsString('type="radio"', $output);
        // The carrier is still named even here — this page is reachable when
        // the skip guard has already been spent.
        $this->assertStringContainsString(SinglePaymentStepProbeShipSet::TITLE, $output);
        $this->assertStringContainsString('id="payment"', $output);
        $this->assertStringContainsString('value="validatepayment"', $output);
        $this->assertStringContainsString('name="paymentid" value="oxidinvoice"', $output);
    }

    private function renderPaymentStep(bool $shippingAutoAssigned, bool $paymentAutoAssigned = false): string
    {
        $bridge = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class);

        $output = $bridge->getTemplateRenderer()->renderTemplate(
            'page/checkout/payment.html.twig',
            ['oView' => new SingleShippingStepProbeView($shippingAutoAssigned, $paymentAutoAssigned)]
        );

        // A shop without a usable frontend theme answers with the template
        // name instead of markup. Say so plainly — a silent pass here would
        // claim the override was verified when nothing was rendered.
        $this->assertNotSame(
            'page/checkout/payment.html.twig',
            trim($output),
            'the shop renderer returned the template name — no frontend theme in this environment'
        );

        return $output;
    }
}
