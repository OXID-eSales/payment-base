<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Application\Controller;

use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentAssignerInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingAssignerInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingSettingsInterface;
use OxidEsales\PaymentBase\Checkout\SinglePaymentResolver;
use OxidEsales\PaymentBase\Checkout\SingleShippingResolver;
use OxidEsales\PaymentBase\Eshop\Application\Controller\PaymentController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

final class FakeSinglePaymentSettings implements SinglePaymentSettingsInterface
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function isAutoAssignEnabled(): bool
    {
        return $this->enabled;
    }
}

/**
 * Shared by the payment and shipping spies so the *order* of the two
 * assignments can be asserted, not just their outcomes. Ordering is
 * load-bearing here (see testShippingIsAssignedBeforePayment) and an
 * end-state assertion would pass on the second request either way.
 */
final class CheckoutAssignmentLog
{
    /** @var list<string> */
    public array $calls = [];
}

final class SpySinglePaymentAssigner implements SinglePaymentAssignerInterface
{
    /** @var list<array{paymentId: string, user: mixed}> */
    public array $calls = [];

    public function __construct(
        private readonly bool $result = true,
        private readonly ?CheckoutAssignmentLog $log = null,
    ) {
    }

    public function assign(string $paymentId, mixed $user): bool
    {
        $this->calls[] = ['paymentId' => $paymentId, 'user' => $user];
        if ($this->log !== null) {
            $this->log->calls[] = 'payment';
        }

        return $this->result;
    }
}

final class FakeSingleShippingSettings implements SingleShippingSettingsInterface
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function isAutoAssignEnabled(): bool
    {
        return $this->enabled;
    }
}

final class SpySingleShippingAssigner implements SingleShippingAssignerInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly bool $result = true,
        private readonly ?CheckoutAssignmentLog $log = null,
    ) {
    }

    public function assign(string $shipSetId): bool
    {
        $this->calls[] = $shipSetId;
        if ($this->log !== null) {
            $this->log->calls[] = 'shipping';
        }

        return $this->result;
    }
}

/**
 * A delivery set as getAllSets() carries it — keyed by id, so the model itself
 * is never asked anything.
 */
final class ListedShipSet
{
    public bool $blSelected = false;
}

/**
 * A payment model as the payment list carries it: it only has to answer
 * whether it collects data on the payment step.
 */
final class ListedPayment
{
    /** @param array<int|string, mixed> $dynValues */
    public function __construct(private readonly array $dynValues = [])
    {
    }

    /** @return array<int|string, mixed> */
    public function getDynValues(): array
    {
        return $this->dynValues;
    }
}

/**
 * Controller under test. The OXID-facing reads (payment list, payment error,
 * user) are the seams; the decision is the code under test.
 */
final class TestablePaymentController extends PaymentController
{
    /**
     * @param array<array-key, mixed>|false $paymentList
     * @param array<array-key, mixed>|false $allSets
     */
    public function __construct(
        private readonly SinglePaymentSettingsInterface $settings,
        private readonly SpySinglePaymentAssigner $assigner,
        private readonly array|false $paymentList,
        private readonly mixed $paymentError = null,
        private readonly mixed $user = 'user-object',
        private readonly ?SingleShippingSettingsInterface $shippingSettings = null,
        private readonly ?SpySingleShippingAssigner $shippingAssigner = null,
        private readonly array|false $allSets = [],
    ) {
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

    protected function getSingleShippingAssigner(): SingleShippingAssignerInterface
    {
        return $this->shippingAssigner ?? new SpySingleShippingAssigner();
    }

    public function getAllSets()
    {
        return $this->allSets;
    }

    protected function getSinglePaymentResolver(): SinglePaymentResolverInterface
    {
        return new SinglePaymentResolver();
    }

    protected function getSinglePaymentAssigner(): SinglePaymentAssignerInterface
    {
        return $this->assigner;
    }

    public function getPaymentList()
    {
        return $this->paymentList;
    }

    public function getPaymentError()
    {
        return $this->paymentError;
    }

    public function getUser()
    {
        return $this->user;
    }
}

/**
 * Sprint 06 — hiding the payment-selection block.
 *
 * Every guard here exists because of a way the shortcut could hurt: hiding a
 * validation error the customer has to act on, or hiding a selection that was
 * never actually assigned. The default is always "show the block".
 */
#[CoversClass(PaymentController::class)]
final class PaymentControllerTest extends TestCase
{
    /** @param array<array-key, mixed>|false $paymentList */
    private function controller(
        array|false $paymentList,
        bool $enabled = true,
        mixed $paymentError = null,
        bool $assignResult = true,
    ): TestablePaymentController {
        return new TestablePaymentController(
            new FakeSinglePaymentSettings($enabled),
            new SpySinglePaymentAssigner($assignResult),
            $paymentList,
            $paymentError,
        );
    }

    public function testSingleMethodIsAssignedAndItsBlockIsLeftOut(): void
    {
        $controller = $this->controller(['oxidinvoice' => new ListedPayment()]);

        $controller->render();

        $this->assertTrue($controller->isSinglePaymentAutoAssigned());
        $this->assertSame('oxidinvoice', $controller->getSinglePaymentId());
    }

    /**
     * Non-PSP proof: pay-on-arrival has no payment module behind it and is
     * treated exactly like a PSP method.
     */
    public function testCoreOnlyMethodIsAssignedToo(): void
    {
        $assigner = new SpySinglePaymentAssigner();
        $controller = new TestablePaymentController(
            new FakeSinglePaymentSettings(true),
            $assigner,
            ['oxidcashondel' => new ListedPayment()],
        );

        $controller->render();

        $this->assertSame([['paymentId' => 'oxidcashondel', 'user' => 'user-object']], $assigner->calls);
        $this->assertTrue($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * The step itself must still render — it also carries the delivery-set
     * choice, and its "next" button is what moves the checkout forward.
     */
    public function testRenderStillReturnsTheParentTemplate(): void
    {
        $controller = $this->controller(['oxidinvoice' => new ListedPayment()]);

        $this->assertSame('', $controller->render());
    }

    public function testTwoMethodsKeepTheSelectionBlock(): void
    {
        $controller = $this->controller([
            'oxidinvoice' => new ListedPayment(),
            'oxidcashondel' => new ListedPayment(),
        ]);

        $controller->render();

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
        $this->assertSame('', $controller->getSinglePaymentId());
    }

    public function testMethodCollectingDataKeepsTheSelectionBlock(): void
    {
        $controller = $this->controller([
            'oxiddebitnote' => new ListedPayment(['lsbankname' => '']),
        ]);

        $controller->render();

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    public function testDisabledSettingKeepsTheSelectionBlock(): void
    {
        $controller = $this->controller(['oxidinvoice' => new ListedPayment()], enabled: false);

        $controller->render();

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * A payment error is a message for the customer. Hiding the selection they
     * would have to correct leaves them stuck on it.
     */
    public function testPaymentErrorKeepsTheSelectionBlock(): void
    {
        $controller = $this->controller(['oxidinvoice' => new ListedPayment()], paymentError: -3);

        $controller->render();

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * Core returns false, not an empty array, when no payment method fits.
     */
    public function testEmptyPaymentListKeepsTheSelectionBlock(): void
    {
        $controller = $this->controller(false);

        $controller->render();

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }

    /**
     * If the method cannot legally be used, the assignment fails — and the
     * customer must see the block, which is where core shows why.
     */
    public function testRefusedAssignmentKeepsTheSelectionBlock(): void
    {
        $controller = $this->controller(['oxidinvoice' => new ListedPayment()], assignResult: false);

        $controller->render();

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
        $this->assertSame('', $controller->getSinglePaymentId());
    }

    public function testNoAssignmentIsAttemptedWhenThereIsAChoice(): void
    {
        $assigner = new SpySinglePaymentAssigner();
        $controller = new TestablePaymentController(
            new FakeSinglePaymentSettings(true),
            $assigner,
            ['oxidinvoice' => new ListedPayment(), 'oxidcashondel' => new ListedPayment()],
        );

        $controller->render();

        $this->assertSame([], $assigner->calls);
    }

    /**
     * Before the step renders, nothing has been decided — the template must not
     * see a stale "hidden" from a previous request object.
     */
    public function testNothingIsAssignedBeforeTheStepRenders(): void
    {
        $controller = $this->controller(['oxidinvoice' => new ListedPayment()]);

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
    }


    // ---------------------------------------------------------------
    // Sprint 07 — hiding the delivery-set selector on the same step.
    // ---------------------------------------------------------------

    /** @param array<array-key, mixed>|false $allSets */
    private function shippingController(
        array|false $allSets,
        bool $enabled = true,
        bool $assignResult = true,
        ?SpySingleShippingAssigner $assigner = null,
    ): TestablePaymentController {
        return new TestablePaymentController(
            new FakeSinglePaymentSettings(false),
            new SpySinglePaymentAssigner(),
            false,
            null,
            'user-object',
            new FakeSingleShippingSettings($enabled),
            $assigner ?? new SpySingleShippingAssigner($assignResult),
            $allSets,
        );
    }

    public function testSingleDeliverySetIsAssignedAndItsSelectorIsLeftOut(): void
    {
        $assigner = new SpySingleShippingAssigner();
        $controller = $this->shippingController(
            ['oxidstandard' => new ListedShipSet()],
            assigner: $assigner,
        );

        $controller->render();

        $this->assertTrue($controller->isSingleShippingAutoAssigned());
        $this->assertSame('oxidstandard', $controller->getSingleShippingId());
        $this->assertSame(['oxidstandard'], $assigner->calls);
    }

    public function testTwoDeliverySetsKeepTheSelector(): void
    {
        $assigner = new SpySingleShippingAssigner();
        $controller = $this->shippingController(
            ['oxidstandard' => new ListedShipSet(), 'express' => new ListedShipSet()],
            assigner: $assigner,
        );

        $controller->render();

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
        $this->assertSame('', $controller->getSingleShippingId());
        $this->assertSame([], $assigner->calls);
    }

    public function testDisabledShippingSettingKeepsTheSelector(): void
    {
        $assigner = new SpySingleShippingAssigner();
        $controller = $this->shippingController(
            ['oxidstandard' => new ListedShipSet()],
            enabled: false,
            assigner: $assigner,
        );

        $controller->render();

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
        $this->assertSame([], $assigner->calls, 'the kill switch must short-circuit');
    }

    /**
     * Core returns false, not an empty array, when the basket is undeliverable.
     * Its own template already hides the block in that case; ours must not
     * claim an assignment happened.
     */
    public function testEmptyDeliverySetListKeepsTheSelector(): void
    {
        $controller = $this->shippingController(false);

        $controller->render();

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    public function testRefusedShippingAssignmentKeepsTheSelector(): void
    {
        $controller = $this->shippingController(
            ['oxidstandard' => new ListedShipSet()],
            assignResult: false,
        );

        $controller->render();

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
        $this->assertSame('', $controller->getSingleShippingId());
    }

    public function testNoDeliverySetIsAssignedBeforeTheStepRenders(): void
    {
        $controller = $this->shippingController(['oxidstandard' => new ListedShipSet()]);

        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    /**
     * The ordering invariant, and the reason this is a sequence assertion
     * rather than an end-state one.
     *
     * SinglePaymentAssigner validates the method against the session's
     * sShipSet. On a first visit that variable does not exist yet — core writes
     * it only in changeshipping(). If the shipping assignment ran second, the
     * payment would be validated against a falsy delivery set on the very
     * request that matters, and an end-state check would still pass because the
     * next request finds sShipSet already written.
     */
    public function testShippingIsAssignedBeforePayment(): void
    {
        $log = new CheckoutAssignmentLog();
        $controller = new TestablePaymentController(
            new FakeSinglePaymentSettings(true),
            new SpySinglePaymentAssigner(true, $log),
            ['oxidinvoice' => new ListedPayment()],
            null,
            'user-object',
            new FakeSingleShippingSettings(true),
            new SpySingleShippingAssigner(true, $log),
            ['oxidstandard' => new ListedShipSet()],
        );

        $controller->render();

        $this->assertSame(['shipping', 'payment'], $log->calls);
    }

    /**
     * The two shortcuts are independent switches: turning shipping off must
     * leave sprint 06's behaviour exactly as it was.
     */
    public function testTheTwoShortcutsAreIndependent(): void
    {
        $controller = new TestablePaymentController(
            new FakeSinglePaymentSettings(true),
            new SpySinglePaymentAssigner(),
            ['oxidinvoice' => new ListedPayment()],
            null,
            'user-object',
            new FakeSingleShippingSettings(false),
            new SpySingleShippingAssigner(),
            ['oxidstandard' => new ListedShipSet()],
        );

        $controller->render();

        $this->assertTrue($controller->isSinglePaymentAutoAssigned());
        $this->assertFalse($controller->isSingleShippingAutoAssigned());
    }

    /**
     * A pending payment error is about the payment selection, not the carrier.
     * With one delivery set the selector offers nothing either way, and the
     * sShipSet write is what lets the customer's retry validate at all — so the
     * shipping assignment deliberately does not carry sprint 06's error guard.
     */
    public function testPaymentErrorDoesNotSuppressTheShippingAssignment(): void
    {
        $controller = new TestablePaymentController(
            new FakeSinglePaymentSettings(true),
            new SpySinglePaymentAssigner(),
            ['oxidinvoice' => new ListedPayment()],
            -3,
            'user-object',
            new FakeSingleShippingSettings(true),
            new SpySingleShippingAssigner(),
            ['oxidstandard' => new ListedShipSet()],
        );

        $controller->render();

        $this->assertFalse($controller->isSinglePaymentAutoAssigned());
        $this->assertTrue($controller->isSingleShippingAutoAssigned());
    }
}
