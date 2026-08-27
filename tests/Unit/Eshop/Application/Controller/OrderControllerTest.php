<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Application\Controller;

use OxidEsales\PaymentBase\Checkout\Contract\PaymentStepSkipGuardInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingSettingsInterface;
use OxidEsales\PaymentBase\Checkout\SinglePaymentResolver;
use OxidEsales\PaymentBase\Checkout\SingleShippingResolver;
use OxidEsales\PaymentBase\Eshop\Application\Controller\OrderController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The payment the order page is about to use, as core's getPayment() hands it
 * over.
 */
final class SelectedPayment
{
    public function __construct(private readonly ?string $id)
    {
    }

    public function getId(): ?string
    {
        return $this->id;
    }
}

/**
 * The delivery set the order page is about to use, as core's getShipSet()
 * hands it over — loaded from the basket's shipping id, or false.
 */
final class SelectedShipSet
{
    public function __construct(private readonly ?string $id)
    {
    }

    public function getId(): ?string
    {
        return $this->id;
    }
}

final class TestableOrderController extends OrderController
{
    public int $paymentListReads = 0;
    public int $deliverySetListReads = 0;

    /**
     * @param array<array-key, mixed> $availablePaymentList
     * @param array<array-key, mixed> $availableDeliverySetList
     */
    public function __construct(
        private readonly SinglePaymentSettingsInterface $settings,
        private readonly mixed $payment,
        private readonly array $availablePaymentList = [],
        private readonly ?SingleShippingSettingsInterface $shippingSettings = null,
        private readonly mixed $shipSet = false,
        private readonly array $availableDeliverySetList = [],
        private readonly ?SpyPaymentStepSkipGuard $skipGuard = null,
    ) {
    }

    protected function getPaymentStepSkipGuard(): PaymentStepSkipGuardInterface
    {
        return $this->skipGuard ?? new SpyPaymentStepSkipGuard();
    }

    protected function getSinglePaymentSettings(): SinglePaymentSettingsInterface
    {
        return $this->settings;
    }

    protected function getSingleShippingSettings(): SingleShippingSettingsInterface
    {
        return $this->shippingSettings ?? new FakeSingleShippingSettings(false);
    }

    protected function getSingleShippingResolver(): SingleShippingResolverInterface
    {
        return new SingleShippingResolver();
    }

    public function getShipSet()
    {
        if ($this->shipSet === 'throw') {
            throw new RuntimeException('delivery set unavailable');
        }

        return $this->shipSet;
    }

    protected function readAvailableDeliverySetList(): array
    {
        $this->deliverySetListReads++;

        return $this->availableDeliverySetList;
    }

    protected function getSinglePaymentResolver(): SinglePaymentResolverInterface
    {
        return new SinglePaymentResolver();
    }

    public function getPayment()
    {
        if ($this->payment === 'throw') {
            throw new RuntimeException('payment unavailable');
        }

        return $this->payment;
    }

    protected function readAvailablePaymentList(): array
    {
        $this->paymentListReads++;

        return $this->availablePaymentList;
    }
}

/**
 * Same controller, but with the real payment-list read left in place — the one
 * that talks to the shop. Under unit conditions there is no basket, which is
 * exactly the "the shop cannot answer" case the read has to survive.
 */
final class UnbootstrappedOrderController extends OrderController
{
    public function __construct(private readonly mixed $payment)
    {
    }

    protected function getSinglePaymentSettings(): SinglePaymentSettingsInterface
    {
        return new FakeSinglePaymentSettings(true);
    }

    protected function getSinglePaymentResolver(): SinglePaymentResolverInterface
    {
        return new SinglePaymentResolver();
    }

    protected function getSingleShippingSettings(): SingleShippingSettingsInterface
    {
        return new FakeSingleShippingSettings(true);
    }

    protected function getSingleShippingResolver(): SingleShippingResolverInterface
    {
        return new SingleShippingResolver();
    }

    protected function getPaymentStepSkipGuard(): PaymentStepSkipGuardInterface
    {
        return new SpyPaymentStepSkipGuard();
    }

    public function getShipSet()
    {
        return new SelectedShipSet('oxidstandard');
    }

    public function getPayment()
    {
        return $this->payment;
    }
}

/**
 * Sprint 06 — hiding the order page's payment block.
 *
 * The block is dropped only when the method on the order really is the shop's
 * only one. Anything else — a choice, a stale selection, a shop that cannot
 * answer — leaves the page exactly as core renders it.
 */
#[CoversClass(OrderController::class)]
final class OrderControllerTest extends TestCase
{
    public function testBlockIsHiddenWhenTheOrderUsesTheOnlyAvailableMethod(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            new SelectedPayment('oxidinvoice'),
            ['oxidinvoice' => new ListedPayment()],
        );

        $this->assertTrue($controller->isSinglePaymentAutoAssigned());
    }

    public function testBlockIsShownWhenTheCustomerHadAChoice(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            new SelectedPayment('oxidinvoice'),
            ['oxidinvoice' => new ListedPayment(), 'oxidcashondel' => new ListedPayment()],
        );

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * Self-healing: the merchant activates a second method mid-session, so the
     * order no longer runs on the shop's only method and the block comes back.
     */
    public function testStaleSelectionShowsTheBlockAgain(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            new SelectedPayment('oxidcashondel'),
            ['oxidinvoice' => new ListedPayment()],
        );

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    public function testDisabledSettingShowsTheBlock(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(false),
            new SelectedPayment('oxidinvoice'),
            ['oxidinvoice' => new ListedPayment()],
        );

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
        $this->assertSame(0, $controller->paymentListReads, 'the kill switch must short-circuit');
    }

    /**
     * Core returns false from getPayment() when nothing valid is selected; the
     * page is about to redirect back to the payment step anyway.
     */
    public function testMissingPaymentShowsTheBlock(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            false,
            ['oxidinvoice' => new ListedPayment()],
        );

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    public function testPaymentWithoutAnIdShowsTheBlock(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            new SelectedPayment(null),
            ['oxidinvoice' => new ListedPayment()],
        );

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    public function testUnreadablePaymentShowsTheBlock(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            'throw',
            ['oxidinvoice' => new ListedPayment()],
        );

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * A method that collects data on the payment step is never the auto-assigned
     * one, so its block stays — the customer must be able to get back to those
     * fields.
     */
    public function testMethodCollectingDataShowsTheBlock(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            new SelectedPayment('oxiddebitnote'),
            ['oxiddebitnote' => new ListedPayment(['lsbankname' => ''])],
        );

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * Without a shop behind it the payment list cannot be read at all. That
     * must produce "show the block", not an exception on the order page.
     */
    public function testShopThatCannotAnswerShowsTheBlock(): void
    {
        $controller = new UnbootstrappedOrderController(new SelectedPayment('oxidinvoice'));

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    public function testDecisionIsComputedOncePerRequest(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            new SelectedPayment('oxidinvoice'),
            ['oxidinvoice' => new ListedPayment()],
        );

        $controller->isSinglePaymentAutoAssigned();
        $controller->isSinglePaymentAutoAssigned();

        $this->assertSame(1, $controller->paymentListReads);
    }

    // ---------------------------------------------------------------
    // Sprint 07 — hiding the order page's shipping-carrier block.
    // ---------------------------------------------------------------

    /** @param array<array-key, mixed> $availableDeliverySetList */
    private function shippingController(
        mixed $shipSet,
        array $availableDeliverySetList,
        bool $enabled = true,
    ): TestableOrderController {
        return new TestableOrderController(
            new FakeSinglePaymentSettings(false),
            false,
            [],
            new FakeSingleShippingSettings($enabled),
            $shipSet,
            $availableDeliverySetList,
        );
    }

    public function testCarrierBlockIsHiddenWhenTheOrderUsesTheOnlyAvailableSet(): void
    {
        $controller = $this->shippingController(
            new SelectedShipSet('oxidstandard'),
            ['oxidstandard' => new ListedShipSet()],
        );

        $this->assertTrue($controller->isSingleShippingAutoAssigned());
    }

    public function testCarrierBlockIsShownWhenTheCustomerHadAChoice(): void
    {
        $controller = $this->shippingController(
            new SelectedShipSet('oxidstandard'),
            ['oxidstandard' => new ListedShipSet(), 'express' => new ListedShipSet()],
        );

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    /**
     * Self-healing: the merchant activates a second delivery set mid-session,
     * so the order no longer runs on the shop's only carrier and the block
     * comes back. This is why the answer is recomputed per request rather than
     * remembered in the session.
     */
    public function testStaleCarrierSelectionShowsTheBlockAgain(): void
    {
        $controller = $this->shippingController(
            new SelectedShipSet('express'),
            ['oxidstandard' => new ListedShipSet()],
        );

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    public function testDisabledShippingSettingShowsTheCarrierBlock(): void
    {
        $controller = $this->shippingController(
            new SelectedShipSet('oxidstandard'),
            ['oxidstandard' => new ListedShipSet()],
            enabled: false,
        );

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
        $this->assertSame(0, $controller->deliverySetListReads, 'the kill switch must short-circuit');
    }

    /**
     * Core returns false from getShipSet() when the basket carries no loadable
     * delivery set.
     */
    public function testMissingShipSetShowsTheCarrierBlock(): void
    {
        $controller = $this->shippingController(false, ['oxidstandard' => new ListedShipSet()]);

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    public function testShipSetWithoutAnIdShowsTheCarrierBlock(): void
    {
        $controller = $this->shippingController(
            new SelectedShipSet(null),
            ['oxidstandard' => new ListedShipSet()],
        );

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    public function testUnreadableShipSetShowsTheCarrierBlock(): void
    {
        $controller = $this->shippingController('throw', ['oxidstandard' => new ListedShipSet()]);

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    /**
     * Without a shop behind it the delivery-set list cannot be read at all.
     * That must produce "show the block", not an exception on the order page.
     */
    public function testShopThatCannotAnswerShowsTheCarrierBlock(): void
    {
        $controller = new UnbootstrappedOrderController(new SelectedPayment('oxidinvoice'));

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    public function testCarrierDecisionIsComputedOncePerRequest(): void
    {
        $controller = $this->shippingController(
            new SelectedShipSet('oxidstandard'),
            ['oxidstandard' => new ListedShipSet()],
        );

        $controller->isSingleShippingAutoAssigned();
        $controller->isSingleShippingAutoAssigned();

        $this->assertSame(1, $controller->deliverySetListReads);
    }

    /**
     * The two blocks are decided independently — hiding the carrier must not
     * drag sprint 06's payment block with it, or vice versa.
     */
    public function testTheTwoBlocksAreDecidedIndependently(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(true),
            new SelectedPayment('oxidinvoice'),
            ['oxidinvoice' => new ListedPayment()],
            new FakeSingleShippingSettings(true),
            new SelectedShipSet('oxidstandard'),
            ['oxidstandard' => new ListedShipSet(), 'express' => new ListedShipSet()],
        );

        $this->assertTrue($controller->isSinglePaymentAutoAssigned());
        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    // ---------------------------------------------------------------
    // Sprint 07 S6 — the other half of the payment step's skip guard.
    // ---------------------------------------------------------------

    /**
     * Reaching this page is what re-arms the shortcut. Without it the payment
     * step would skip exactly once per session and then never again.
     */
    public function testRenderingTheOrderPageReArmsTheSkip(): void
    {
        $guard = new SpyPaymentStepSkipGuard(maySkip: false);
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(false),
            false,
            [],
            new FakeSingleShippingSettings(false),
            false,
            [],
            $guard,
        );

        $controller->render();

        $this->assertSame(1, $guard->clears);
        $this->assertTrue($guard->maySkip());
    }

    public function testRenderStillReturnsTheParentTemplate(): void
    {
        $controller = new TestableOrderController(
            new FakeSinglePaymentSettings(false),
            false,
        );

        $this->assertSame('', $controller->render());
    }
}
