<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentAssignerInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface;
use OxidEsales\PaymentBase\Checkout\PaymentCandidateFactory;
use OxidEsales\PaymentBase\Checkout\SinglePaymentAssigner;
use OxidEsales\PaymentBase\Checkout\SinglePaymentResolver;
use OxidEsales\PaymentBase\Checkout\SinglePaymentSettings;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 06 — the single-payment shortcut against a real shop.
 *
 * The unit tests prove the rule; these prove the wiring the rule depends on:
 * the services are reachable from the container (a private service would be
 * inlined away and the controller extensions would die on a missing id), the
 * class-extension chain really carries the two controllers, and the rule reads
 * OXID's actual Payment model correctly — including the one core method that
 * must never be auto-assigned because it asks for bank details.
 */
#[Group('integration')]
class SinglePaymentAutoAssignTest extends IntegrationTestCase
{
    public function testResolverIsReachableFromTheContainer(): void
    {
        $resolver = ContainerFactory::getInstance()->getContainer()
            ->get(SinglePaymentResolverInterface::class);

        $this->assertInstanceOf(SinglePaymentResolver::class, $resolver);
    }

    public function testAssignerIsReachableFromTheContainer(): void
    {
        $assigner = ContainerFactory::getInstance()->getContainer()
            ->get(SinglePaymentAssignerInterface::class);

        $this->assertInstanceOf(SinglePaymentAssigner::class, $assigner);
    }

    public function testSettingsAreReachableFromTheContainer(): void
    {
        $settings = ContainerFactory::getInstance()->getContainer()
            ->get(SinglePaymentSettingsInterface::class);

        $this->assertInstanceOf(SinglePaymentSettings::class, $settings);
    }

    /**
     * The setting ships enabled, so a freshly installed shop with one payment
     * method gets the shortcut without anyone touching the admin.
     */
    public function testShortcutIsEnabledByDefault(): void
    {
        /** @var SinglePaymentSettingsInterface $settings */
        $settings = ContainerFactory::getInstance()->getContainer()
            ->get(SinglePaymentSettingsInterface::class);

        $this->assertTrue($settings->isAutoAssignEnabled());
    }

    /**
     * The extension only works if the shop really builds it into the payment
     * step's class chain — with four other modules already extending that
     * controller, "it is in metadata.php" is not the same as "it is in the
     * chain".
     */
    public function testPaymentStepControllerIsInTheClassChain(): void
    {
        $controller = oxNew(\OxidEsales\Eshop\Application\Controller\PaymentController::class);

        $this->assertInstanceOf(
            \OxidEsales\PaymentBase\Eshop\Application\Controller\PaymentController::class,
            $controller
        );
    }

    public function testOrderStepControllerIsInTheClassChain(): void
    {
        $controller = oxNew(\OxidEsales\Eshop\Application\Controller\OrderController::class);

        $this->assertInstanceOf(
            \OxidEsales\PaymentBase\Eshop\Application\Controller\OrderController::class,
            $controller
        );
    }

    /**
     * The order page asks this getter before rendering the payment block.
     */
    public function testOrderControllerAnswersTheTemplateQuestion(): void
    {
        $controller = oxNew(\OxidEsales\Eshop\Application\Controller\OrderController::class);

        $this->assertIsBool($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * Invoice is a plain OXID core payment method — no module, no PSP. It asks
     * the customer for nothing, so a shop that offers only invoice can assign
     * it silently. This is the "works for non-payment-base methods" requirement,
     * checked against the real model and the real database row.
     */
    public function testCoreInvoiceMethodIsAutoAssignable(): void
    {
        $resolver = new SinglePaymentResolver();

        $this->assertSame(
            'oxidinvoice',
            $resolver->resolve(PaymentCandidateFactory::fromPaymentList(
                ['oxidinvoice' => $this->loadPayment('oxidinvoice')]
            ))
        );
    }

    /**
     * Direct debit collects bank details on the payment step. Even as the only
     * method it must keep that step — the fields have nowhere else to live.
     */
    public function testCoreDebitNoteMethodIsNeverAutoAssignable(): void
    {
        $payment = $this->loadPayment('oxiddebitnote');
        $candidates = PaymentCandidateFactory::fromPaymentList(['oxiddebitnote' => $payment]);

        $this->assertTrue(
            $candidates[0]->requiresUserInput(),
            'oxiddebitnote is expected to carry dynamic input fields (OXVALDESC)'
        );
        $this->assertNull((new SinglePaymentResolver())->resolve($candidates));
    }

    /**
     * Two methods mean the customer chooses — the regression net for every
     * existing shop.
     */
    public function testTwoCoreMethodsLeaveTheChoiceToTheCustomer(): void
    {
        $resolver = new SinglePaymentResolver();

        $this->assertNull($resolver->resolve(PaymentCandidateFactory::fromPaymentList([
            'oxidinvoice' => $this->loadPayment('oxidinvoice'),
            'oxidcashondel' => $this->loadPayment('oxidcashondel'),
        ])));
    }

    private function loadPayment(string $paymentId): Payment
    {
        /** @var Payment $payment */
        $payment = oxNew(Payment::class);
        $this->assertTrue($payment->load($paymentId), "core payment {$paymentId} must exist");

        return $payment;
    }
}
