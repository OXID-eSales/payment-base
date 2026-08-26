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
 * Stands in for the payment controller: the single-payment answers are real,
 * and a one-entry payment list makes the core block render its radio button.
 */
class SinglePaymentStepProbeView
{
    public function __construct(private readonly bool $autoAssigned)
    {
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return $this->autoAssigned;
    }

    public function getSinglePaymentId(): string
    {
        return $this->autoAssigned ? 'oxidinvoice' : '';
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

    /**
     * The delivery-set selector renders only when the step has sets to offer.
     *
     * @return array<string, mixed>
     */
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

class SinglePaymentStepProbeShipSet
{
    public bool $blSelected = true;

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class SinglePaymentStepProbePayment
{
    /** @return array<int, mixed> */
    public function getDynValues(): array
    {
        return [];
    }

    public function getPrice(): mixed
    {
        return null;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Sprint 06 — the payment step's selection block.
 *
 * What must disappear is the block the customer reads: heading, radio, price,
 * description. What must NOT disappear is the `<form id="payment">` around it —
 * the step's "next" button lives outside the form and submits it by id, so
 * removing the element would strand the customer. This renders the shop's real
 * payment template and checks both halves of that.
 */
#[Group('integration')]
class SinglePaymentStepTemplateTest extends IntegrationTestCase
{
    public function testSelectionIsVisibleWhenTheCustomerHasAChoice(): void
    {
        $output = $this->renderPaymentStep(autoAssigned: false);

        $this->assertStringContainsString('name="paymentid"', $output);
        $this->assertStringContainsString('type="radio"', $output);
    }

    public function testSelectionDisappearsForASinglePaymentMethod(): void
    {
        $output = $this->renderPaymentStep(autoAssigned: true);

        $this->assertStringNotContainsString('type="radio"', $output);
        $this->assertStringNotContainsString('payment-option', $output);
    }

    /**
     * The submit path has to survive: same form id, same action, and the
     * assigned method travels with the POST instead of a radio button.
     */
    public function testTheFormThatTheNextButtonSubmitsSurvives(): void
    {
        $output = $this->renderPaymentStep(autoAssigned: true);

        $this->assertStringContainsString('id="payment"', $output);
        $this->assertStringContainsString('value="validatepayment"', $output);
        $this->assertStringContainsString('name="paymentid" value="oxidinvoice"', $output);
    }

    /**
     * The delivery-set choice shares this step and must be unaffected — this is
     * why the step is not skipped outright.
     */
    public function testShippingSelectionSurvives(): void
    {
        $output = $this->renderPaymentStep(autoAssigned: true);

        $this->assertStringContainsString('name="sShipSet"', $output);
    }

    private function renderPaymentStep(bool $autoAssigned): string
    {
        $bridge = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class);

        return $bridge->getTemplateRenderer()->renderTemplate(
            'page/checkout/payment.html.twig',
            ['oView' => new SinglePaymentStepProbeView($autoAssigned)]
        );
    }
}
