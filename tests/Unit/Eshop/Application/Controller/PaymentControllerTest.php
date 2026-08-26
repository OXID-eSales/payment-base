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
use OxidEsales\PaymentBase\Checkout\SinglePaymentResolver;
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

final class SpySinglePaymentAssigner implements SinglePaymentAssignerInterface
{
    /** @var list<array{paymentId: string, user: mixed}> */
    public array $calls = [];

    public function __construct(private readonly bool $result = true)
    {
    }

    public function assign(string $paymentId, mixed $user): bool
    {
        $this->calls[] = ['paymentId' => $paymentId, 'user' => $user];

        return $this->result;
    }
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
    /** @param array<array-key, mixed>|false $paymentList */
    public function __construct(
        private readonly SinglePaymentSettingsInterface $settings,
        private readonly SpySinglePaymentAssigner $assigner,
        private readonly array|false $paymentList,
        private readonly mixed $paymentError = null,
        private readonly mixed $user = 'user-object',
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

}
