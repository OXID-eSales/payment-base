<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\PaymentStepSkipGuardInterface;
use OxidEsales\PaymentBase\Checkout\PaymentStepSkipGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GuardSpySession
{
    /** @var list<string> */
    public array $deleted = [];
    /** @var array<string, mixed> */
    public array $written = [];

    /** @param array<string, mixed> $variables */
    public function __construct(private array $variables = [])
    {
    }

    public function getVariable(string $name): mixed
    {
        return $this->variables[$name] ?? null;
    }

    public function setVariable(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
        $this->written[$name] = $value;
    }

    public function deleteVariable(string $name): void
    {
        unset($this->variables[$name]);
        $this->deleted[] = $name;
    }
}

final class TestablePaymentStepSkipGuard extends PaymentStepSkipGuard
{
    public function __construct(private readonly mixed $session)
    {
    }

    protected function getSession(): mixed
    {
        if ($this->session === 'throw') {
            throw new RuntimeException('no session');
        }

        return $this->session;
    }
}

/**
 * Sprint 07 S6 — the one thing that makes skipping the payment step safe.
 *
 * The order step redirects back to `cl=payment` whenever it cannot resolve a
 * payment (`OrderController::render()`), and the payment step would redirect
 * forward again — two 302s pointing at each other. The inputs on both sides are
 * identical, so they should always agree, but "should always agree" is not a
 * property worth betting a checkout on.
 *
 * This guard makes the loop structurally impossible instead of merely unlikely:
 * the step may skip at most once until the order step actually renders. If the
 * order step bounces back, the second visit renders the payment step normally —
 * reduced to its bare form, but with a working "next" button.
 */
#[CoversClass(PaymentStepSkipGuard::class)]
final class PaymentStepSkipGuardTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            PaymentStepSkipGuardInterface::class,
            new TestablePaymentStepSkipGuard(new GuardSpySession())
        );
    }

    public function testAFreshCheckoutMaySkip(): void
    {
        $this->assertTrue((new TestablePaymentStepSkipGuard(new GuardSpySession()))->maySkip());
    }

    public function testASkipAlreadyTakenMayNotSkipAgain(): void
    {
        $session = new GuardSpySession();
        $guard = new TestablePaymentStepSkipGuard($session);

        $guard->markSkipped();

        $this->assertFalse($guard->maySkip());
    }

    /**
     * The loop case, spelled out: the step skipped, the order step bounced back
     * without clearing the guard, and we are on the payment step again. It must
     * render this time.
     */
    public function testTheSecondArrivalAfterABounceMayNotSkip(): void
    {
        $guard = new TestablePaymentStepSkipGuard(
            new GuardSpySession(['oepbPaymentStepSkipped' => true])
        );

        $this->assertFalse($guard->maySkip());
    }

    /**
     * Reaching the order step is what re-arms the shortcut — the customer can
     * legitimately come back to `cl=payment` later and be forwarded again.
     */
    public function testClearingReArmsTheShortcut(): void
    {
        $session = new GuardSpySession(['oepbPaymentStepSkipped' => true]);
        $guard = new TestablePaymentStepSkipGuard($session);

        $guard->clear();

        $this->assertTrue($guard->maySkip());
        $this->assertSame(['oepbPaymentStepSkipped'], $session->deleted);
    }

    public function testMarkingWritesTheFlag(): void
    {
        $session = new GuardSpySession();

        (new TestablePaymentStepSkipGuard($session))->markSkipped();

        $this->assertSame(['oepbPaymentStepSkipped' => true], $session->written);
    }

    /**
     * A shop that cannot answer must not be skipped past. Refusing the shortcut
     * costs a click; taking it on a broken session could strand the customer.
     */
    public function testShopFailureRefusesTheShortcut(): void
    {
        $this->assertFalse((new TestablePaymentStepSkipGuard('throw'))->maySkip());
    }

    public function testShopFailureDoesNotEscapeFromMarkOrClear(): void
    {
        $guard = new TestablePaymentStepSkipGuard('throw');

        $guard->markSkipped();
        $guard->clear();

        $this->assertFalse($guard->maySkip());
    }
}
