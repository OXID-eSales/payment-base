<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\AdminRefusalFeedbackInterface;
use OxidEsales\PaymentBase\Admin\ClassicMoneyActionAdapter;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\AdminChannelInterface;
use OxidEsales\PaymentBase\Payment\MoneyOperationRunnerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassicMoneyActionAdapterTest extends TestCase
{
    /** @var list<AdminPaymentOperation> */
    private array $operations = [];

    /** @var list<string> */
    private array $shown = [];

    private ?PaymentOperationResult $answer = null;

    private ?AdminAccessDeniedException $denial = null;

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function actions(): iterable
    {
        yield 'capture' => ['capture', true];
        yield 'refund' => ['refund', true];
        yield 'cancel' => ['cancel', true];
        yield 'anything else is the provider\'s own' => ['resync', false];
    }

    #[DataProvider('actions')]
    public function testHandles_OnlyTheMoneyActions(string $action, bool $expected): void
    {
        self::assertSame($expected, $this->adapter()->handles($action));
    }

    public function testCapture_FormFieldsBecomeTheOperation_AndADecimalCommaIsRead(): void
    {
        $this->adapter()->execute('capture', ['capture_amount' => '40,50', 'capture_reason' => 'shipped', 'idempotency_key' => 'form-key-0001'], 'order-1');

        $operation = $this->operations[0];
        self::assertSame(AdminPaymentOperation::CAPTURE, $operation->kind);
        self::assertSame('order-1', $operation->orderId);
        self::assertSame(40.5, $operation->amount);
        self::assertSame('shipped', $operation->reason);
        self::assertSame('form-key-0001', $operation->clientKey);
    }

    public function testCapture_WithoutAnAmount_CapturesInFull(): void
    {
        $this->adapter()->execute('capture', ['capture_amount' => ''], 'order-1');

        self::assertNull($this->operations[0]->amount);
    }

    public function testRefund_FormFieldsBecomeTheOperation(): void
    {
        $this->adapter()->execute('refund', ['refund_amount' => '12.5', 'refund_reason' => 'duplicate', 'refund_description' => 'Twice charged'], 'order-1');

        $operation = $this->operations[0];
        self::assertSame(AdminPaymentOperation::REFUND, $operation->kind);
        self::assertSame(12.5, $operation->amount);
        self::assertSame('duplicate', $operation->reason);
        self::assertSame('Twice charged', $operation->description);
    }

    public function testCancel_BecomesAVoidWithItsReason(): void
    {
        $this->adapter()->execute('cancel', ['cancel_reason' => 'out of stock'], 'order-1');

        self::assertSame(AdminPaymentOperation::VOID, $this->operations[0]->kind);
        self::assertSame('out of stock', $this->operations[0]->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableAmounts(): iterable
    {
        yield 'missing' => [''];
        yield 'text' => ['ten'];
    }

    #[DataProvider('unreadableAmounts')]
    public function testRefund_WithoutAReadableAmount_IsShownAsRefusedAndNothingRuns(string $amount): void
    {
        $this->adapter()->execute('refund', ['refund_amount' => $amount], 'order-1');

        self::assertSame([], $this->operations);
        self::assertCount(1, $this->shown);
    }

    public function testRefusalOfThePath_IsShownToTheAdmin(): void
    {
        $this->answer = PaymentOperationResult::refused(PaymentRefusal::invalid('amount', 'exceeds_refundable', 'The amount is more than what is left to refund'));

        $this->adapter()->execute('refund', ['refund_amount' => '999'], 'order-1');

        self::assertSame(['The amount is more than what is left to refund'], $this->shown);
    }

    public function testDenial_IsShownToTheAdmin_AndDoesNotFail(): void
    {
        $this->denial = new AdminAccessDeniedException('right_missing', 'You may not refund payments');

        $this->adapter()->execute('refund', ['refund_amount' => '5'], 'order-1');

        self::assertSame(['You may not refund payments'], $this->shown);
    }

    public function testSuccess_ShowsNothing(): void
    {
        $this->adapter()->execute('cancel', [], 'order-1');

        self::assertSame([], $this->shown);
    }

    private function adapter(): ClassicMoneyActionAdapter
    {
        $runner = new class ($this) implements MoneyOperationRunnerInterface {
            public function __construct(private readonly ClassicMoneyActionAdapterTest $test)
            {
            }

            public function run(AdminPaymentOperation $operation, AdminChannelInterface $channel): PaymentOperationResult
            {
                return $this->test->received($operation);
            }
        };
        $feedback = new class ($this) implements AdminRefusalFeedbackInterface {
            public function __construct(private readonly ClassicMoneyActionAdapterTest $test)
            {
            }

            public function show(AdminActionRefusedException $refusal): void
            {
                $this->test->shows($refusal->getMessage());
            }
        };

        return new ClassicMoneyActionAdapter($runner, $this->createStub(AdminChannelInterface::class), $feedback);
    }

    /** @internal for the doubles above */
    public function received(AdminPaymentOperation $operation): PaymentOperationResult
    {
        $this->operations[] = $operation;
        if ($this->denial !== null) {
            throw $this->denial;
        }

        return $this->answer ?? PaymentOperationResult::done(new \OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails('c', 'paypal', 'fulfilled', 1.0, 1.0, 0.0, 'EUR', []));
    }

    /** @internal for the doubles above */
    public function shows(string $message): void
    {
        $this->shown[] = $message;
    }
}
