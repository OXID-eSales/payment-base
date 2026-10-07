<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextInterface;
use OxidEsales\PaymentBase\Checkout\Contract\PaymentStepSkipGuardInterface;
use OxidEsales\PaymentBase\Checkout\PaymentStepSkipGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sprint 15 / S2: the guard reads a CheckoutContextInterface instead of the
 * Registry session. This double records what it was asked to write and
 * remove, or throws on every call to model a shop that cannot answer.
 */
final class GuardSpyContext implements CheckoutContextInterface
{
    /** @var list<string> */
    public array $removed = [];
    /** @var array<string, mixed> */
    public array $written = [];

    /** @param array<string, mixed> $variables */
    public function __construct(private array $variables = [], private readonly bool $broken = false)
    {
    }

    public function getScopeId(): string
    {
        $this->failIfBroken();

        return 'session-1';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->failIfBroken();

        return $this->variables[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->failIfBroken();
        $this->variables[$key] = $value;
        $this->written[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->failIfBroken();
        unset($this->variables[$key]);
        $this->removed[] = $key;
    }

    private function failIfBroken(): void
    {
        if ($this->broken) {
            throw new RuntimeException('no session');
        }
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
            new PaymentStepSkipGuard(new GuardSpyContext())
        );
    }

    public function testAFreshCheckoutMaySkip(): void
    {
        $this->assertTrue((new PaymentStepSkipGuard(new GuardSpyContext()))->maySkip());
    }

    public function testASkipAlreadyTakenMayNotSkipAgain(): void
    {
        $session = new GuardSpyContext();
        $guard = new PaymentStepSkipGuard($session);

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
        $guard = new PaymentStepSkipGuard(
            new GuardSpyContext(['oepbPaymentStepSkipped' => true])
        );

        $this->assertFalse($guard->maySkip());
    }

    /**
     * Reaching the order step is what re-arms the shortcut — the customer can
     * legitimately come back to `cl=payment` later and be forwarded again.
     */
    public function testClearingReArmsTheShortcut(): void
    {
        $session = new GuardSpyContext(['oepbPaymentStepSkipped' => true]);
        $guard = new PaymentStepSkipGuard($session);

        $guard->clear();

        $this->assertTrue($guard->maySkip());
        $this->assertSame(['oepbPaymentStepSkipped'], $session->removed);
    }

    public function testMarkingWritesTheFlag(): void
    {
        $session = new GuardSpyContext();

        (new PaymentStepSkipGuard($session))->markSkipped();

        $this->assertSame(['oepbPaymentStepSkipped' => true], $session->written);
    }

    /**
     * A shop that cannot answer must not be skipped past. Refusing the shortcut
     * costs a click; taking it on a broken session could strand the customer.
     */
    public function testShopFailureRefusesTheShortcut(): void
    {
        $this->assertFalse((new PaymentStepSkipGuard(new GuardSpyContext(broken: true)))->maySkip());
    }

    public function testShopFailureDoesNotEscapeFromMarkOrClear(): void
    {
        $guard = new PaymentStepSkipGuard(new GuardSpyContext(broken: true));

        $guard->markSkipped();
        $guard->clear();

        $this->assertFalse($guard->maySkip());
    }
}
