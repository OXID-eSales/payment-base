<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentAssignerInterface;
use OxidEsales\PaymentBase\Checkout\SinglePaymentAssigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SpyBasket
{
    public int $paymentResets = 0;
    public ?string $shipping = null;
    public bool $shippingWasSet = false;

    public function __construct(private readonly float $priceForPayment = 100.0)
    {
    }

    public function setPayment(?string $paymentId = null): void
    {
        if ($paymentId === null) {
            $this->paymentResets++;
        }
    }

    public function getPriceForPayment(): float
    {
        return $this->priceForPayment;
    }

    public function setShipping(?string $shipSetId = null): void
    {
        $this->shipping = $shipSetId;
        $this->shippingWasSet = true;
    }
}

final class SpySession
{
    /** @var array<string, mixed> */
    public array $written = [];
    /** @var list<string> */
    public array $deleted = [];

    /** @param array<string, mixed> $variables */
    public function __construct(
        private array $variables = [],
        private readonly ?SpyBasket $basket = null,
    ) {
    }

    public function getBasket(): ?SpyBasket
    {
        return $this->basket;
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

final class SpyPayment
{
    /** @var array<string, mixed>|null */
    public ?array $validationArgs = null;

    public function __construct(private readonly bool $valid = true)
    {
    }

    public function isValidPayment(
        mixed $dynValue,
        mixed $shopId,
        mixed $user,
        mixed $basketPrice,
        mixed $shipSetId
    ): bool {
        $this->validationArgs = [
            'dynValue' => $dynValue,
            'shopId' => $shopId,
            'user' => $user,
            'basketPrice' => $basketPrice,
            'shipSetId' => $shipSetId,
        ];

        return $this->valid;
    }
}

/**
 * Assigner under test with the three shop seams replaced.
 */
final class TestableSinglePaymentAssigner extends SinglePaymentAssigner
{
    public function __construct(
        private readonly mixed $session,
        private readonly mixed $payment,
        private readonly mixed $shopId = 1,
    ) {
    }

    protected function getSession(): mixed
    {
        if ($this->session === 'throw') {
            throw new RuntimeException('no session');
        }

        return $this->session;
    }

    protected function loadPayment(string $paymentId): mixed
    {
        return $this->payment;
    }

    protected function getShopId(): mixed
    {
        return $this->shopId;
    }
}

/**
 * Sprint 06 — assigning the single payment method.
 *
 * The assignment must be indistinguishable from a customer clicking the only
 * radio button: same core validation, same session keys, no shortcuts. That is
 * also what keeps the order step from bouncing back to the payment step, since
 * it re-validates with exactly these inputs.
 */
#[CoversClass(SinglePaymentAssigner::class)]
final class SinglePaymentAssignerTest extends TestCase
{
    private const USER = 'user-object';

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            SinglePaymentAssignerInterface::class,
            new TestableSinglePaymentAssigner(new SpySession(), new SpyPayment())
        );
    }

    public function testValidPaymentIsWrittenExactlyLikeCoreDoes(): void
    {
        $basket = new SpyBasket();
        $session = new SpySession(['sShipSet' => 'oxidstandard'], $basket);
        $assigner = new TestableSinglePaymentAssigner($session, new SpyPayment());

        $this->assertTrue($assigner->assign('oxidinvoice', self::USER));
        $this->assertSame('oxidinvoice', $session->written['paymentid']);
        $this->assertSame([], $session->written['dynvalue']);
        $this->assertSame(['_selected_paymentid'], $session->deleted);
        $this->assertSame('oxidstandard', $basket->shipping);
    }

    /**
     * Core clears the basket's payment before validating so the lazily cached
     * id cannot mask a change. Same sequence here.
     */
    public function testBasketPaymentIsResetBeforeValidation(): void
    {
        $basket = new SpyBasket();
        $assigner = new TestableSinglePaymentAssigner(new SpySession([], $basket), new SpyPayment());

        $assigner->assign('oxidinvoice', self::USER);

        $this->assertSame(1, $basket->paymentResets);
    }

    /**
     * The no-redirect-loop invariant: the order step re-validates the payment
     * with the session's dynvalue and sShipSet. If we validated with anything
     * else, an assignment could succeed here and fail there — and the two
     * controllers would bounce the customer between them forever.
     */
    public function testValidationUsesTheSameInputsTheOrderStepWillUse(): void
    {
        $payment = new SpyPayment();
        $session = new SpySession(['sShipSet' => 'oxidstandard'], new SpyBasket(42.5));
        $assigner = new TestableSinglePaymentAssigner($session, $payment, 7);

        $assigner->assign('oxidinvoice', self::USER);

        $this->assertSame([
            'dynValue' => [],
            'shopId' => 7,
            'user' => self::USER,
            'basketPrice' => 42.5,
            'shipSetId' => 'oxidstandard',
        ], $payment->validationArgs);
    }

    public function testRejectedPaymentWritesNothing(): void
    {
        $session = new SpySession(['sShipSet' => 'oxidstandard'], new SpyBasket());
        $assigner = new TestableSinglePaymentAssigner($session, new SpyPayment(false));

        $this->assertFalse($assigner->assign('oxidinvoice', self::USER));
        $this->assertSame([], $session->written);
        $this->assertSame([], $session->deleted);
    }

    public function testUnloadablePaymentIsNotAssigned(): void
    {
        $session = new SpySession([], new SpyBasket());
        $assigner = new TestableSinglePaymentAssigner($session, null);

        $this->assertFalse($assigner->assign('oxidinvoice', self::USER));
        $this->assertSame([], $session->written);
    }

    public function testMissingBasketIsNotAssigned(): void
    {
        $assigner = new TestableSinglePaymentAssigner(new SpySession(), new SpyPayment());

        $this->assertFalse($assigner->assign('oxidinvoice', self::USER));
    }

    public function testMissingUserIsNotAssigned(): void
    {
        $session = new SpySession([], new SpyBasket());
        $assigner = new TestableSinglePaymentAssigner($session, new SpyPayment());

        $this->assertFalse($assigner->assign('oxidinvoice', null));
        $this->assertSame([], $session->written);
    }

    public function testEmptyPaymentIdIsNotAssigned(): void
    {
        $session = new SpySession([], new SpyBasket());
        $assigner = new TestableSinglePaymentAssigner($session, new SpyPayment());

        $this->assertFalse($assigner->assign('', self::USER));
        $this->assertSame([], $session->written);
    }

    /**
     * An optional convenience must never take checkout down: anything the shop
     * throws at us means "no shortcut", not "no checkout".
     */
    public function testShopFailureDegradesToNoAssignment(): void
    {
        $assigner = new TestableSinglePaymentAssigner('throw', new SpyPayment());

        $this->assertFalse($assigner->assign('oxidinvoice', self::USER));
    }
}
