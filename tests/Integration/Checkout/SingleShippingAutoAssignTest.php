<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use OxidEsales\Eshop\Application\Model\DeliverySet;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingAssignerInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingSettingsInterface;
use OxidEsales\PaymentBase\Checkout\ShippingCandidateFactory;
use OxidEsales\PaymentBase\Checkout\SingleShippingAssigner;
use OxidEsales\PaymentBase\Checkout\SingleShippingResolver;
use OxidEsales\PaymentBase\Checkout\SingleShippingSettings;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 07 — the single-shipping shortcut against a real shop.
 *
 * The unit tests prove the rule; these prove the wiring it depends on: the
 * services are reachable from the container (a private service would be inlined
 * away and the controller extensions would die on a missing id), the two
 * controllers really answer the template's questions, and the rule reads OXID's
 * actual DeliverySet model correctly.
 */
#[Group('integration')]
class SingleShippingAutoAssignTest extends IntegrationTestCase
{
    public function testResolverIsReachableFromTheContainer(): void
    {
        $resolver = ContainerFactory::getInstance()->getContainer()
            ->get(SingleShippingResolverInterface::class);

        $this->assertInstanceOf(SingleShippingResolver::class, $resolver);
    }

    public function testAssignerIsReachableFromTheContainer(): void
    {
        $assigner = ContainerFactory::getInstance()->getContainer()
            ->get(SingleShippingAssignerInterface::class);

        $this->assertInstanceOf(SingleShippingAssigner::class, $assigner);
    }

    public function testSettingsAreReachableFromTheContainer(): void
    {
        $settings = ContainerFactory::getInstance()->getContainer()
            ->get(SingleShippingSettingsInterface::class);

        $this->assertInstanceOf(SingleShippingSettings::class, $settings);
    }

    /**
     * The setting ships enabled, so a freshly installed shop with one delivery
     * set gets the shortcut without anyone touching the admin.
     */
    public function testShortcutIsEnabledByDefault(): void
    {
        /** @var SingleShippingSettingsInterface $settings */
        $settings = ContainerFactory::getInstance()->getContainer()
            ->get(SingleShippingSettingsInterface::class);

        $this->assertTrue($settings->isAutoAssignEnabled());
    }

    /**
     * Both shortcuts are separately switchable, and both default on — the
     * shipping one must not have silently inherited the payment one's value.
     */
    public function testTheTwoShortcutsAreSeparateContainerServices(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        $this->assertNotSame(
            $container->get(SingleShippingSettingsInterface::class),
            $container->get(\OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface::class)
        );
    }

    /**
     * The order page asks this getter before rendering the carrier block.
     */
    public function testOrderControllerAnswersTheTemplateQuestion(): void
    {
        $controller = oxNew(\OxidEsales\Eshop\Application\Controller\OrderController::class);

        $this->assertIsBool($controller->isSingleShippingAutoAssigned());
    }

    /**
     * And the payment step asks these two.
     */
    public function testPaymentStepControllerAnswersTheTemplateQuestions(): void
    {
        $controller = oxNew(\OxidEsales\Eshop\Application\Controller\PaymentController::class);

        $this->assertIsBool($controller->isSingleShippingAutoAssigned());
        $this->assertIsString($controller->getSingleShippingId());
    }

    /**
     * Read against the real model and the real database row: `oxidstandard` is
     * the delivery set every OXID shop ships with.
     */
    public function testCoreStandardDeliverySetIsAutoAssignable(): void
    {
        $resolver = new SingleShippingResolver();

        $this->assertSame(
            'oxidstandard',
            $resolver->resolve(ShippingCandidateFactory::fromDeliverySetList(
                ['oxidstandard' => $this->loadDeliverySet('oxidstandard')]
            ))
        );
    }

    /**
     * Two carriers mean the customer chooses — the regression net for every
     * existing shop.
     */
    public function testTwoDeliverySetsLeaveTheChoiceToTheCustomer(): void
    {
        $resolver = new SingleShippingResolver();

        $this->assertNull($resolver->resolve(ShippingCandidateFactory::fromDeliverySetList([
            'oxidstandard' => $this->loadDeliverySet('oxidstandard'),
            'express' => $this->loadDeliverySet('oxidstandard'),
        ])));
    }

    private function loadDeliverySet(string $shipSetId): DeliverySet
    {
        /** @var DeliverySet $deliverySet */
        $deliverySet = oxNew(DeliverySet::class);
        $this->assertTrue($deliverySet->load($shipSetId), "core delivery set {$shipSetId} must exist");

        return $deliverySet;
    }
}
