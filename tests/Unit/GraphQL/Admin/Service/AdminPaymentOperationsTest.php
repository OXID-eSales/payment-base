<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionBoundsResolver;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionOptionsResolver;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperations;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotency;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelProviderActionRunner;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\RequestShopOrdersInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\AdapterEffects;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\CountingStepUp;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakeBoundsPanelProvider;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakeOptionsPanelProvider;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakePanelProvider;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryIdempotency;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\RecordingAudit;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Capture, refund and void with the provider adapter replaced by a fake at the panel-provider boundary (the same
 * boundary the classic Payment tab uses). No provider sandbox is involved: what is proven here is everything
 * payment-base decides - bounds, step-up, idempotency, audit, the read-back of what the adapter achieved.
 */
final class AdminPaymentOperationsTest extends TestCase
{
    private InMemoryContracts $contracts;
    private InMemoryIdempotency $idempotencyRows;
    private RecordingAudit $audit;
    private CountingStepUp $stepUp;
    private FakePanelProvider $adapter;
    private bool $orderInShop = true;
    private ?PaymentPanelProviderInterface $panel = null;

    protected function setUp(): void
    {
        $this->contracts = new InMemoryContracts();
        $this->idempotencyRows = new InMemoryIdempotency();
        $this->audit = new RecordingAudit();
        $this->stepUp = new CountingStepUp();
        $this->adapter = new FakePanelProvider('paypal', AdapterEffects::successful());
    }

    // --- capture ----------------------------------------------------------------------------------------------

    public function testCapture_AuthorisedContract_CapturesTheFullAmountThroughTheAdapter(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $result = $this->operations()->capture('order-1', null, 'key-capture-1');

        self::assertNull($result->refusal());
        self::assertSame('fulfilled', $result->payment()->state);
        self::assertSame(100.0, $result->payment()->amountCaptured);
        self::assertSame('capture', $this->adapter->calls[0]['action']);
        self::assertSame('order-1', $this->adapter->calls[0]['orderId']);
        self::assertArrayNotHasKey('capture_amount', $this->adapter->calls[0]['request'], 'no amount: the provider captures in full');
    }

    public function testCapture_PartialAmount_IsHandedToTheAdapter(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $result = $this->operations()->capture('order-1', 40.0, 'key-capture-1');

        self::assertNull($result->refusal());
        self::assertSame(40.0, $this->adapter->calls[0]['request']['capture_amount']);
        self::assertSame(40.0, $result->payment()->amountCaptured);
    }

    public function testCapture_AmountIsRoundedToTheMinorUnitOfTheCurrency(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $this->operations()->capture('order-1', 33.333333, 'key-capture-1');

        self::assertSame(33.33, $this->adapter->calls[0]['request']['capture_amount']);
    }

    public function testCapture_NeedsAStepUp_AndRefusesBeforeAnythingElseWhenItIsMissing(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->stepUp = new CountingStepUp(refuse: true);

        try {
            $this->operations()->capture('order-1', null, 'key-capture-1');
            self::fail('the step-up refusal must propagate');
        } catch (\RuntimeException $refusal) {
            self::assertSame('step_up_required', $refusal->getMessage());
        }

        self::assertSame([], $this->adapter->calls);
        self::assertSame([], $this->idempotencyRows->records);
        self::assertSame('threw:' . \RuntimeException::class, $this->audit->rows[0]['outcome'], 'the refusal is audited too');
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function refusedCaptureAmounts(): iterable
    {
        yield 'zero' => [0.0, 'amount', 'amount_invalid'];
        yield 'negative' => [-5.0, 'amount', 'amount_invalid'];
        yield 'not a number' => [NAN, 'amount', 'amount_invalid'];
        yield 'infinite' => [INF, 'amount', 'amount_invalid'];
        yield 'more than authorised' => [100.01, 'amount', 'exceeds_capturable'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedCaptureAmounts')]
    public function testCapture_InvalidAmount_IsRefusedAndTheAdapterNeverCalled(float $amount, string $field, string $code): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $result = $this->operations()->capture('order-1', $amount, 'key-capture-1');

        $refusal = $result->refusal();
        self::assertNotNull($refusal);
        self::assertSame($field, $refusal->field);
        self::assertSame($code, $refusal->code);
        self::assertSame([], $this->adapter->calls);
    }

    public function testCapture_ContractNotAuthorised_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::pending('order-1'));

        $refusal = $this->operations()->capture('order-1', null, 'key-capture-1')->refusal();

        self::assertSame('not_capturable', $refusal?->code);
        self::assertSame([], $this->adapter->calls);
    }

    public function testCapture_AlreadyCapturedContract_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        self::assertSame('not_capturable', $this->operations()->capture('order-1', null, 'key-capture-1')->refusal()?->code);
    }

    // --- refund -----------------------------------------------------------------------------------------------

    public function testRefund_CapturedContract_RefundsThroughTheAdapterWithItsReason(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $result = $this->operations()->refund('order-1', 30.0, 'Damaged in transit', 'key-refund-1');

        self::assertNull($result->refusal());
        self::assertSame(30.0, $result->payment()->amountRefunded);
        self::assertSame(70.0, $result->payment()->amountRefundable());
        self::assertSame(
            ['refund_amount' => 30.0, 'refund_reason' => 'Damaged in transit', 'idempotency_key' => 'key-refund-1'],
            $this->adapter->calls[0]['request'],
        );
        self::assertSame(1, $this->stepUp->required);
    }

    public function testRefund_WithoutReason_SendsNone(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $this->operations()->refund('order-1', 10.0, null, 'key-refund-1');

        self::assertArrayNotHasKey('refund_reason', $this->adapter->calls[0]['request']);
    }

    public function testRefund_NeedsAStepUp(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $this->stepUp = new CountingStepUp(refuse: true);

        $this->expectException(\RuntimeException::class);
        try {
            $this->operations()->refund('order-1', 10.0, null, 'key-refund-1');
        } finally {
            self::assertSame([], $this->adapter->calls);
        }
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function refusedRefundAmounts(): iterable
    {
        yield 'zero' => [0.0, 'amount_invalid'];
        yield 'negative' => [-1.0, 'amount_invalid'];
        yield 'NaN' => [NAN, 'amount_invalid'];
        yield 'more than refundable' => [60.01, 'exceeds_refundable'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedRefundAmounts')]
    public function testRefund_InvalidAmount_IsRefusedAndTheAdapterNeverCalled(float $amount, string $code): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0, 40.0));

        $refusal = $this->operations()->refund('order-1', $amount, null, 'key-refund-1')->refusal();

        self::assertSame('amount', $refusal?->field);
        self::assertSame($code, $refusal?->code);
        self::assertSame([], $this->adapter->calls);
    }

    public function testRefund_NothingRefundable_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->contracts->save(ContractFactory::fulfilled('order-2', 'paypal', 100.0, 100.0, 100.0));

        self::assertSame('not_refundable', $this->operations()->refund('order-1', 5.0, null, 'key-refund-1')->refusal()?->code);
        self::assertSame('not_refundable', $this->operations()->refund('order-2', 5.0, null, 'key-refund-2')->refusal()?->code);
    }

    public function testRefund_ReasonTooLongOrWithControlCharacters_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $tooLong = $this->operations()->refund('order-1', 5.0, str_repeat('x', 256), 'key-refund-1')->refusal();
        $control = $this->operations()->refund('order-1', 5.0, "line\nbreak", 'key-refund-2')->refusal();

        self::assertSame(['reason', 'too_long'], [$tooLong?->field, $tooLong?->code]);
        self::assertSame(['reason', 'invalid_characters'], [$control?->field, $control?->code]);
        self::assertSame([], $this->adapter->calls);
    }

    // --- void -------------------------------------------------------------------------------------------------

    public function testVoid_AuthorisedContract_CancelsTheAuthorisationThroughTheAdapter(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));

        $result = $this->operations()->void('order-1', 'key-void-1');

        self::assertNull($result->refusal());
        self::assertSame('cancelled', $result->payment()->state);
        self::assertSame('cancel', $this->adapter->calls[0]['action']);
        self::assertSame(0, $this->stepUp->required, 'releasing an authorisation moves no money: no step-up');
    }

    public function testVoid_CapturedContract_IsRefused_RefundIsTheWay(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        self::assertSame('not_voidable', $this->operations()->void('order-1', 'key-void-1')->refusal()?->code);
        self::assertSame([], $this->adapter->calls);
    }

    // --- what the adapter did ---------------------------------------------------------------------------------

    public function testAdapterThatChangedNothing_IsReportedAsNotConfirmed_AndTheKeyCanBeRetried(): void
    {
        $this->adapter = new FakePanelProvider('paypal', AdapterEffects::silentlyRefusing());
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $first = $this->operations()->refund('order-1', 10.0, null, 'key-refund-1');
        $second = $this->operations()->refund('order-1', 10.0, null, 'key-refund-1');

        self::assertSame('not_confirmed', $first->refusal()?->code);
        self::assertSame('not_confirmed', $second->refusal()?->code, 'a released key runs again instead of reporting in_progress');
        self::assertCount(2, $this->adapter->calls);
    }

    public function testAdapterThatThrows_IsReportedAsProviderFailure_WithoutItsMessage(): void
    {
        $this->adapter = new FakePanelProvider('paypal', static function (): void {
            throw new \RuntimeException('Authorization failed for client 12345');
        });
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $refusal = $this->operations()->refund('order-1', 10.0, null, 'key-refund-1')->refusal();

        self::assertSame('provider_failed', $refusal?->code);
        self::assertStringNotContainsString('12345', $refusal->message);
    }

    public function testNoProviderForTheContract_IsReported(): void
    {
        $this->adapter = new FakePanelProvider('stripe', AdapterEffects::successful());
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal'));

        self::assertSame('provider_unavailable', $this->operations()->refund('order-1', 10.0, null, 'key-refund-1')->refusal()?->code);
    }

    // --- scope ------------------------------------------------------------------------------------------------

    public function testOrderWithoutAContract_IsNotFound(): void
    {
        $refusal = $this->operations()->capture('order-invoice', null, 'key-capture-1')->refusal();

        self::assertSame('not_found', $refusal?->kind);
    }

    public function testOrderOfAnotherShop_IsNotFound_AndNothingRuns(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $this->orderInShop = false;

        $refusal = $this->operations()->refund('order-1', 10.0, null, 'key-refund-1')->refusal();

        self::assertSame('not_found', $refusal?->kind);
        self::assertSame([], $this->adapter->calls);
    }

    // --- idempotency ------------------------------------------------------------------------------------------

    public function testSameKeyTwice_RefundsOnce_AndTheRetryReportsTheEarlierSuccess(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $first = $this->operations()->refund('order-1', 30.0, null, 'key-refund-1');
        $retry = $this->operations()->refund('order-1', 30.0, null, 'key-refund-1');

        self::assertNull($first->refusal());
        self::assertNull($retry->refusal());
        self::assertCount(1, $this->adapter->calls, 'the provider is called once');
        self::assertSame(30.0, $retry->payment()->amountRefunded);
    }

    public function testSecondCallWhileTheFirstIsRunning_IsRefusedAsInProgress(): void
    {
        $inner = null;
        $operations = null;
        $this->adapter = new FakePanelProvider('paypal', function () use (&$inner, &$operations): void {
            $inner = $operations?->refund('order-1', 10.0, null, 'key-refund-1');
        });
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $operations = $this->operations();

        $operations->refund('order-1', 10.0, null, 'key-refund-1');

        self::assertInstanceOf(PaymentOperationResult::class, $inner);
        self::assertSame('in_progress', $inner->refusal()?->code);
    }

    public function testRefusedCall_ReleasesItsKey_SoACorrectedCallCanUseIt(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $tooMuch = $this->operations()->refund('order-1', 500.0, null, 'key-refund-1');
        $corrected = $this->operations()->refund('order-1', 50.0, null, 'key-refund-1');

        self::assertSame('exceeds_refundable', $tooMuch->refusal()?->code);
        self::assertNull($corrected->refusal());
    }

    public function testWithoutAClientKey_TheKeyFollowsTheOrdersStateAndAmount(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $first = $this->operations()->refund('order-1', 10.0, null, null);
        $second = $this->operations()->refund('order-1', 10.0, null, null);

        self::assertNull($first->refusal());
        self::assertNull($second->refusal(), 'after the first refund the order stands differently: a second 10.00 is a new refund');
        self::assertCount(2, $this->adapter->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'spaces' => ['key with spaces'];
        yield 'quotes' => ['key"quoted"1234'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testMalformedIdempotencyKey_IsRefused(string $key): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $refusal = $this->operations()->refund('order-1', 10.0, null, $key)->refusal();

        self::assertSame(['idempotencyKey', 'invalid_key'], [$refusal?->field, $refusal?->code]);
        self::assertSame([], $this->adapter->calls);
    }

    // --- provider bounds (PAY-1 S4) -----------------------------------------------------------------------------

    public function testProviderCapturableBound_AllowsAFurtherCapture_ThePaymentBaseNumbersWouldRefuse(): void
    {
        $this->useBounds(static fn (): AdminActionBounds => new AdminActionBounds(capturable: 70.0));
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 30.0));
        $this->contracts->save(ContractFactory::fulfilled('order-2', 'paypal', 100.0, 30.0));

        // the fake adapter cannot re-capture a captured contract; what is proven is that the rule let the call through
        $accepted = $this->operations()->capture('order-1', 70.0, 'key-capture-1')->refusal();
        $tooMuch = $this->operations()->capture('order-2', 70.01, 'key-capture-2')->refusal();

        self::assertContains($accepted?->code, ['provider_failed', 'not_confirmed'], 'passed the bound, reached the provider');
        self::assertSame('exceeds_capturable', $tooMuch?->code);
        self::assertCount(1, $this->adapter->calls);
    }

    public function testProviderRefundableBound_LowerThanTheLocalOne_IsTheLimit(): void
    {
        $this->useBounds(static fn (): AdminActionBounds => new AdminActionBounds(refundable: 10.0));
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $refusal = $this->operations()->refund('order-1', 20.0, null, 'key-refund-1')->refusal();

        self::assertSame('exceeds_refundable', $refusal?->code);
        self::assertSame([], $this->adapter->calls);
    }

    public function testProviderBoundNotReadable_RefusesTheWriteAsProviderUnavailable_AndZeroesTheRead(): void
    {
        $this->useBounds(static function (): never {
            throw new \RuntimeException('provider down');
        });
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));
        $this->contracts->save(ContractFactory::authorized('order-2'));

        $refund = $this->operations()->refund('order-1', 5.0, null, 'key-refund-1');
        $capture = $this->operations()->capture('order-2', null, 'key-capture-1');
        $void = $this->operations()->void('order-2', 'key-void-1');

        self::assertSame(['orderId', 'provider_unavailable'], [$refund->refusal()?->field, $refund->refusal()?->code]);
        self::assertSame('provider_unavailable', $capture->refusal()?->code);
        self::assertSame('provider_unavailable', $void->refusal()?->code);
        self::assertSame([], $this->adapter->calls);
    }

    public function testProviderWithoutBounds_GetsTheSameAnswersAsBefore(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        self::assertSame('exceeds_refundable', $this->operations()->refund('order-1', 101.0, null, 'key-refund-1')->refusal()?->code);
    }

    /**
     * @param \Closure(\OxidEsales\PaymentBase\Contract\PaymentContractInterface): ?AdminActionBounds $answer
     */
    private function useBounds(\Closure $answer): void
    {
        $this->panel = new FakeBoundsPanelProvider($this->adapter, $answer);
    }

    // --- audit ------------------------------------------------------------------------------------------------

    public function testEveryCallIsAudited_WithFieldNamesAndTheAmountOnly(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $this->operations()->refund('order-1', 30.0, 'Damaged goods, call Mr Smith', 'key-refund-1');
        $this->operations()->refund('order-1', 500.0, null, 'key-refund-2');

        self::assertSame(
            [
                ['operation' => 'adminPaymentRefund', 'orderId' => 'order-1', 'fields' => ['amount', 'reason'], 'amount' => 30.0, 'outcome' => 'success'],
                ['operation' => 'adminPaymentRefund', 'orderId' => 'order-1', 'fields' => ['amount'], 'amount' => 500.0, 'outcome' => 'exceeds_refundable'],
            ],
            $this->audit->rows,
        );
    }

    public function testCaptureAndVoidAreAuditedUnderTheirOwnNames(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->contracts->save(ContractFactory::authorized('order-2'));

        $this->operations()->capture('order-1', null, 'key-capture-1');
        $this->operations()->void('order-2', 'key-void-1');

        self::assertSame(['adminPaymentCapture', 'adminPaymentVoid'], array_column($this->audit->rows, 'operation'));
        self::assertSame([[], []], array_column($this->audit->rows, 'fields'));
    }

    // --- reasons and description (PAY-1 S3) ---------------------------------------------------------------

    public function testCapture_Reason_IsHandedOverAsCaptureReason(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));

        $this->operations()->capture('order-1', null, 'key-capture-1', 'Goods shipped');

        self::assertSame('Goods shipped', $this->adapter->calls[0]['request']['capture_reason']);
    }

    public function testVoid_Reason_IsHandedOverAsCancelReason(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));

        $this->operations()->void('order-1', 'key-void-1', 'Customer cancelled');

        self::assertSame('Customer cancelled', $this->adapter->calls[0]['request']['cancel_reason']);
    }

    public function testRefund_Description_IsHandedOverAsRefundDescription(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $this->operations()->refund('order-1', 10.0, null, 'key-refund-1', 'Refund for order 1');

        self::assertSame('Refund for order 1', $this->adapter->calls[0]['request']['refund_description']);
        self::assertArrayNotHasKey('refund_reason', $this->adapter->calls[0]['request']);
    }

    public function testBlankReasonAndDescription_AreNotSent(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $this->operations()->refund('order-1', 10.0, '  ', 'key-refund-1', '');

        self::assertSame(['refund_amount' => 10.0, 'idempotency_key' => 'key-refund-1'], $this->adapter->calls[0]['request']);
    }

    public function testProviderWithoutOptions_AcceptsAnyRefundReasonWithinTheCeiling(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $result = $this->operations()->refund('order-1', 10.0, 'anything at all', 'key-refund-1');

        self::assertNull($result->refusal());
    }

    public function testProviderWithAReasonList_RefusesAReasonOutsideIt_BeforeTheProviderIsCalled(): void
    {
        $this->useOptions(new AdminActionOptions(['duplicate', 'fraudulent'], ['abandoned'], false, 255));
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $this->contracts->save(ContractFactory::authorized('order-2'));

        $refund = $this->operations()->refund('order-1', 10.0, 'because', 'key-refund-1')->refusal();
        $void = $this->operations()->void('order-2', 'key-void-1', 'duplicate')->refusal();

        self::assertSame(['reason', 'reason_invalid'], [$refund?->field, $refund?->code]);
        self::assertSame(['reason', 'reason_invalid'], [$void?->field, $void?->code], 'the void list is its own');
        self::assertSame([], $this->adapter->calls);
    }

    public function testProviderWithAReasonList_AcceptsAListedReason_AndAnOmittedOne(): void
    {
        $this->useOptions(new AdminActionOptions(['duplicate'], [], false, 255));
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        self::assertNull($this->operations()->refund('order-1', 10.0, 'duplicate', 'key-refund-1')->refusal());
        self::assertNull($this->operations()->refund('order-1', 10.0, null, 'key-refund-2')->refusal());
        self::assertSame('duplicate', $this->adapter->calls[0]['request']['refund_reason']);
    }

    public function testProviderLimit_IsEnforcedOnReasonAndDescription_AsTooLong(): void
    {
        $this->useOptions(new AdminActionOptions([], [], true, 10));
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $this->contracts->save(ContractFactory::authorized('order-2'));

        $reason = $this->operations()->refund('order-1', 5.0, str_repeat('x', 11), 'key-refund-1')->refusal();
        $description = $this->operations()->refund('order-1', 5.0, null, 'key-refund-2', str_repeat('x', 11))->refusal();
        $capture = $this->operations()->capture('order-2', null, 'key-capture-1', str_repeat('x', 11))->refusal();

        self::assertSame(['reason', 'too_long'], [$reason?->field, $reason?->code]);
        self::assertSame(['description', 'too_long'], [$description?->field, $description?->code]);
        self::assertSame(['reason', 'too_long'], [$capture?->field, $capture?->code]);
        self::assertSame([], $this->adapter->calls);
    }

    public function testDescriptionWithControlCharacters_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $refusal = $this->operations()->refund('order-1', 5.0, null, 'key-refund-1', "a\nb")->refusal();

        self::assertSame(['description', 'invalid_characters'], [$refusal?->field, $refusal?->code]);
    }

    public function testAuditNamesReasonAndDescriptionAsFields_NeverTheirValues(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));
        $this->contracts->save(ContractFactory::authorized('order-2'));

        $this->operations()->refund('order-1', 10.0, 'r', 'key-refund-1', 'secret text');
        $this->operations()->capture('order-2', null, 'key-capture-1', 'why');

        self::assertSame([['amount', 'reason', 'description'], ['reason']], array_column($this->audit->rows, 'fields'));
        self::assertStringNotContainsString('secret', (string) json_encode($this->audit->rows));
    }

    public function testActionOptionsOfTheProvider_AreOnThePaymentRead(): void
    {
        $options = new AdminActionOptions(['duplicate'], [], true, 40);
        $this->useOptions($options);
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $payment = $this->operations()->refund('order-1', 5.0, null, 'key-refund-1')->payment();

        self::assertSame($options, $payment->actionOptions());
    }

    private function useOptions(AdminActionOptions $options): void
    {
        $this->panel = new FakeOptionsPanelProvider($this->adapter, $options);
    }

    private function operations(): AdminPaymentOperations
    {
        $now = new DateTimeImmutable('2026-10-26 10:00:00');
        $registry = new PaymentPanelRegistry([$this->panel ?? $this->adapter]);
        $shop = new class ($this->orderInShop) implements RequestShopOrdersInterface {
            public function __construct(private readonly bool $contains)
            {
            }

            public function contains(string $orderId): bool
            {
                return $this->contains;
            }
        };

        return new AdminPaymentOperations(
            new PaymentContractDetailsReader($this->contracts, $this->createStub(TransactionRepositoryInterface::class), new PanelActionOptionsResolver($registry, new NullLogger()), new PanelActionBoundsResolver($registry, new NullLogger())),
            new PanelProviderActionRunner($registry, $this->contracts, new NullLogger()),
            new OperationIdempotency($this->idempotencyRows, static fn (): DateTimeImmutable => $now),
            $this->stepUp,
            $this->audit,
            $shop,
            new NullLogger(),
        );
    }
}
