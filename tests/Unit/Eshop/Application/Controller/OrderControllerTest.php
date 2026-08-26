<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Application\Controller;

use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface;
use OxidEsales\PaymentBase\Checkout\SinglePaymentResolver;
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

final class TestableOrderController extends OrderController
{
    public int $paymentListReads = 0;

    /** @param array<array-key, mixed> $availablePaymentList */
    public function __construct(
        private readonly SinglePaymentSettingsInterface $settings,
        private readonly mixed $payment,
        private readonly array $availablePaymentList = [],
    ) {
    }

    protected function getSinglePaymentSettings(): SinglePaymentSettingsInterface
    {
        return $this->settings;
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
}
