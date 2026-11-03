<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminActionOptionsResolverInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotency;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\RequestShopOrdersInterface;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\CancelRequest;
use OxidEsales\PaymentBase\Payment\CaptureBooking;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Payment\CapturePolicy;
use OxidEsales\PaymentBase\Payment\CaptureOperation;
use OxidEsales\PaymentBase\Payment\CaptureRequest;
use OxidEsales\PaymentBase\Payment\MoneyOperationRunner;
use OxidEsales\PaymentBase\Payment\ProviderGatewayRegistry;
use OxidEsales\PaymentBase\Payment\RefundOperation;
use OxidEsales\PaymentBase\Payment\RefundPolicy;
use OxidEsales\PaymentBase\Payment\RefundRequest;
use OxidEsales\PaymentBase\Payment\RefundResult;
use OxidEsales\PaymentBase\Payment\VoidOperation;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryIdempotency;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\RecordingAudit;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\ControllableOrderLock;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\FakeGateway;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\InMemoryCaptureLedger;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\InMemoryRefundLedger;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\RecordingChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OxidEsales\PaymentBase\Tests\Unit\Fixture\NoActionBounds;

/**
 * VR-22 A3 - the one money path: rights, step-up, audit, the order lock, the atomic idempotency claim, the policies,
 * the provider's gateway and the ledgers. The gateway is a fake at the port providers implement; what is proven here
 * is everything payment-base decides.
 */
final class MoneyOperationRunnerTest extends TestCase
{
    private InMemoryContracts $contracts;
    private InMemoryIdempotency $idempotencyRows;
    private RecordingAudit $audit;
    private ControllableOrderLock $lock;
    private FakeGateway $gateway;
    private InMemoryCaptureLedger $captures;
    private InMemoryRefundLedger $refunds;
    private bool $orderInShop = true;
    private ?AdminActionOptions $options = null;

    protected function setUp(): void
    {
        $this->contracts = new InMemoryContracts();
        $this->idempotencyRows = new InMemoryIdempotency();
        $this->audit = new RecordingAudit();
        $this->lock = new ControllableOrderLock();
        $this->gateway = new FakeGateway('paypal');
        $this->captures = new InMemoryCaptureLedger($this->contracts);
        $this->refunds = new InMemoryRefundLedger($this->contracts);
    }

    // --- capture ----------------------------------------------------------------------------------------------

    public function testCapture_AuthorisedContract_CapturesTheFullAmountThroughTheGatewayAndBooksIt(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $result = $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));

        self::assertNull($result->refusal());
        self::assertSame(100.0, $this->gateway->requests[0]->amount);
        self::assertSame(['cap-1' => 100.0], $this->captures->booked);
        self::assertSame(100.0, $result->payment()->amountCaptured);
    }

    public function testCapture_PartialAmount_IsHandedToTheGateway(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $result = $this->perform(AdminPaymentOperation::capture('order-1', 40.0, 'key-capture-1', 'shipped'));

        self::assertNull($result->refusal());
        $request = $this->gateway->requests[0];
        self::assertInstanceOf(CaptureRequest::class, $request);
        self::assertSame(40.0, $request->amount);
        self::assertSame('EUR', $request->currency);
        self::assertSame('shipped', $request->reason);
        self::assertSame(40.0, $result->payment()->amountCaptured);
    }

    public function testCapture_AmountIsRoundedToTheMinorUnitOfTheCurrency(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $this->perform(AdminPaymentOperation::capture('order-1', 33.333333, 'key-capture-1'));

        self::assertSame(33.33, $this->gateway->requests[0]->amount);
    }

    public function testCapture_NeedsTheCaptureRightAndAStepUp_BeforeAnythingElse(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $channel = $this->channel();

        $this->runOn($channel, AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));

        self::assertSame([PaymentRightsProvider::CAPTURE], $channel->rights);
        self::assertSame(1, $channel->stepUps);
    }

    public function testCapture_WithoutTheRight_IsDeniedAndNothingRuns(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $channel = $this->channel(deniedRight: PaymentRightsProvider::CAPTURE);

        try {
            $this->runOn($channel, AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));
            self::fail('the denied admin must not capture');
        } catch (AdminAccessDeniedException $denied) {
            self::assertSame('right_missing', $denied->denialCode());
        }

        self::assertSame([], $this->gateway->requests);
        self::assertSame(0, $channel->stepUps, 'no step-up is asked of an admin who may not do it at all');
        self::assertSame('threw:' . AdminAccessDeniedException::class, $this->audit->rows[0]['outcome'], 'a denied attempt is audited too');
    }

    public function testCapture_WithoutAFreshStepUp_IsDeniedAndNothingRuns(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));

        try {
            $this->runOn($this->channel(refuseStepUp: true), AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));
            self::fail('no step-up, no capture');
        } catch (AdminAccessDeniedException $denied) {
            self::assertSame('step_up_required', $denied->denialCode());
        }

        self::assertSame([], $this->gateway->requests);
        self::assertSame([], $this->idempotencyRows->records, 'nothing was claimed');
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
        yield 'above the authorisation' => [100.02, 'amount', 'exceeds_capturable'];
    }

    #[DataProvider('refusedCaptureAmounts')]
    public function testCapture_InvalidAmount_IsRefusedAndTheGatewayNeverCalled(float $amount, string $field, string $code): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));

        $refusal = $this->perform(AdminPaymentOperation::capture('order-1', $amount, 'key-capture-1'))->refusal();

        self::assertSame($field, $refusal?->field);
        self::assertSame($code, $refusal->code);
        self::assertSame([], $this->gateway->requests);
    }

    public function testCapture_ContractNotAuthorised_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::pending('order-1'));

        self::assertSame('not_capturable', $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'))->refusal()?->code);
        self::assertSame([], $this->gateway->requests);
    }

    public function testCapture_AlreadyFullyCapturedContract_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        self::assertSame('not_capturable', $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'))->refusal()?->code);
    }

    // --- refund -----------------------------------------------------------------------------------------------

    public function testRefund_CapturedContract_RefundsThroughTheGatewayAndBooksItByItsProviderId(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $result = $this->perform(AdminPaymentOperation::refund('order-1', 30.0, 'requested_by_customer', 'key-refund-1', 'Broken item'));

        self::assertNull($result->refusal());
        $request = $this->gateway->requests[0];
        self::assertInstanceOf(RefundRequest::class, $request);
        self::assertSame(30.0, $request->amount);
        self::assertSame('requested_by_customer', $request->reason);
        self::assertSame('Broken item', $request->description);
        self::assertSame(['amount' => 30.0, 'source' => 'admin', 'succeeded' => true], $this->refunds->booked['re-1']);
        self::assertSame(30.0, $result->payment()->amountRefunded);
    }

    public function testRefund_NeedsTheRefundRightAndAStepUp(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $channel = $this->channel();

        $this->runOn($channel, AdminPaymentOperation::refund('order-1', 10.0, null, 'key-refund-1'));

        self::assertSame([PaymentRightsProvider::REFUND], $channel->rights);
        self::assertSame(1, $channel->stepUps);
    }

    public function testRefund_PendingAtTheProvider_IsBookedAsNotYetSucceeded(): void
    {
        $this->gateway = new FakeGateway('paypal', null, RefundResult::PENDING);
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $this->perform(AdminPaymentOperation::refund('order-1', 10.0, null, 'key-refund-1'));

        self::assertFalse($this->refunds->booked['re-1']['succeeded']);
    }

    public function testRefund_FailedAtTheProvider_IsRefusedAndNotBooked(): void
    {
        $this->gateway = new FakeGateway('paypal', null, RefundResult::FAILED);
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $refusal = $this->perform(AdminPaymentOperation::refund('order-1', 10.0, null, 'key-refund-1'))->refusal();

        self::assertSame('provider_failed', $refusal?->code);
        self::assertSame([], $this->refunds->booked);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function refusedRefundAmounts(): iterable
    {
        yield 'zero' => [0.0, 'amount_invalid'];
        yield 'negative' => [-1.0, 'amount_invalid'];
        yield 'above what is left' => [70.01, 'exceeds_refundable'];
    }

    #[DataProvider('refusedRefundAmounts')]
    public function testRefund_InvalidAmount_IsRefusedAndTheGatewayNeverCalled(float $amount, string $code): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0, 30.0));

        self::assertSame($code, $this->perform(AdminPaymentOperation::refund('order-1', $amount, null, 'key-refund-1'))->refusal()?->code);
        self::assertSame([], $this->gateway->requests);
    }

    public function testRefund_NothingRefundable_IsRefused(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0, 100.0));

        self::assertSame('not_refundable', $this->perform(AdminPaymentOperation::refund('order-1', 1.0, null, 'key-refund-1'))->refusal()?->code);
    }

    public function testRefund_ReasonTooLong_IsRefusedBeforeTheGatewayIsCalled(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $refusal = $this->perform(AdminPaymentOperation::refund('order-1', 10.0, str_repeat('x', 256), 'key-refund-1'))->refusal();

        self::assertSame('too_long', $refusal?->code);
        self::assertSame([], $this->gateway->requests);
    }

    // --- void -------------------------------------------------------------------------------------------------

    public function testVoid_AuthorisedContract_CancelsThroughTheGateway_WithoutAStepUp(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $channel = $this->channel();

        $result = $this->runOn($channel, AdminPaymentOperation::void('order-1', 'key-void-1', 'duplicate'));

        self::assertNull($result->refusal());
        $request = $this->gateway->requests[0];
        self::assertInstanceOf(CancelRequest::class, $request);
        self::assertSame('duplicate', $request->reason);
        self::assertSame([PaymentRightsProvider::CAPTURE], $channel->rights);
        self::assertSame(0, $channel->stepUps, 'releasing an authorisation moves no money');
    }

    public function testVoid_CapturedContract_IsRefused_RefundIsTheWay(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        self::assertSame('not_voidable', $this->perform(AdminPaymentOperation::void('order-1', 'key-void-1'))->refusal()?->code);
        self::assertSame([], $this->gateway->requests);
    }

    // --- who handles it ---------------------------------------------------------------------------------------

    public function testProviderWithoutAGateway_IsReportedUnavailable_AndTheKeyCanBeRetried(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'stripe'));

        self::assertSame('provider_unavailable', $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'))->refusal()?->code);

        $this->gateway = new FakeGateway('stripe');
        self::assertNull($this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'))->refusal());
    }

    public function testOrderWithoutAContract_IsNotFound(): void
    {
        self::assertSame('not_found', $this->perform(AdminPaymentOperation::capture('order-9', null, 'key-capture-1'))->refusal()?->code);
    }

    public function testOrderOfAnotherShop_IsNotFound_AndNothingRuns(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->orderInShop = false;

        self::assertSame('not_found', $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'))->refusal()?->code);
        self::assertSame(0, $this->lock->acquired);
        self::assertSame([], $this->gateway->requests);
    }

    // --- provider failures ------------------------------------------------------------------------------------

    public function testProviderRefusal_BecomesProviderRefusedWithTheProvidersSentence_AndTheKeyIsReleased(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->gateway = new FakeGateway('paypal', static function (): void {
            throw new AdminActionRefusedException('amount_too_small', 'The amount is below the provider minimum');
        });

        $refusal = $this->perform(AdminPaymentOperation::capture('order-1', 1.0, 'key-capture-1'))->refusal();

        self::assertSame('provider_refused', $refusal?->code);
        self::assertSame('The amount is below the provider minimum', $refusal->message);
        self::assertSame([], $this->captures->booked);
        $this->gateway = new FakeGateway('paypal');
        self::assertNull($this->perform(AdminPaymentOperation::capture('order-1', 1.0, 'key-capture-1'))->refusal(), 'the key was released');
    }

    public function testOtherProviderExceptions_AreProviderFailed_WithoutTheirMessage(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->gateway = new FakeGateway('paypal', static function (): void {
            throw new RuntimeException('sk_live_secret exploded');
        });

        $refusal = $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'))->refusal();

        self::assertSame('provider_failed', $refusal?->code);
        self::assertStringNotContainsString('sk_live', $refusal->message);
    }

    public function testBookingThatFailsAfterTheProviderMovedMoney_IsNotReleased_SoTheRetryCannotMoveItAgainBlindly(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));
        $failing = new class () implements ProviderCaptureLedgerInterface {
            public function book(string $contractId, string $providerCaptureId, float $amount, string $currency, string $source): CaptureBooking
            {
                throw new RuntimeException('database gone');
            }
        };
        $runner = $this->runnerWith($failing);

        try {
            $runner->run(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'), $this->channel());
            self::fail('the booking failure must reach the caller');
        } catch (RuntimeException $failure) {
            self::assertSame('database gone', $failure->getMessage());
        }

        $retry = $runner->run(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'), $this->channel());
        self::assertSame('in_progress', $retry->refusal()?->code);
        self::assertCount(1, $this->gateway->requests);
    }

    // --- idempotency and the lock -----------------------------------------------------------------------------

    public function testSameKeyTwice_CapturesOnce_AndTheRetryReportsTheEarlierSuccess(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $operation = AdminPaymentOperation::capture('order-1', null, 'key-capture-1');

        $this->perform($operation);
        $retry = $this->perform($operation);

        self::assertNull($retry->refusal());
        self::assertCount(1, $this->gateway->requests);
    }

    public function testTheGatewayGetsTheSameIdempotencyKey_ForEveryAttemptOfTheSameCall(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->gateway = new FakeGateway('paypal', static function (): void {
            throw new RuntimeException('timeout');
        });
        $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));
        $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));

        self::assertCount(2, $this->gateway->requests);
        self::assertSame($this->gateway->requests[0]->idempotencyKey, $this->gateway->requests[1]->idempotencyKey);
        self::assertNotSame('', $this->gateway->requests[0]->idempotencyKey);
    }

    public function testSecondCallWhileTheFirstIsRunning_IsRefusedAsInProgress(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $inner = null;
        $this->lock->open = true;
        $this->gateway = new FakeGateway('paypal', function () use (&$inner): void {
            $inner = $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));
        });

        $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));

        self::assertSame('in_progress', $inner?->refusal()?->code);
        self::assertCount(1, $this->gateway->requests);
    }

    public function testDifferentClientKeysSerialiseOnOrderLock(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));
        $second = null;
        $this->gateway = new FakeGateway('paypal', function () use (&$second): void {
            $second = $this->perform(AdminPaymentOperation::refund('order-1', 60.0, null, 'key-refund-2'));
        });

        $first = $this->perform(AdminPaymentOperation::refund('order-1', 60.0, null, 'key-refund-1'));

        self::assertNull($first->refusal());
        self::assertSame('in_progress', $second?->refusal()?->code, 'a different key does not get past the order lock');
        self::assertCount(1, $this->gateway->requests, 'one PSP call for two calls with different keys');
    }

    public function testTheGatewayIsCalledWhileTheOrderLockIsHeld_AndTheBoundIsReadUnderIt(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $heldDuringCall = false;
        $this->gateway = new FakeGateway('paypal', function () use (&$heldDuringCall): void {
            $heldDuringCall = $this->lock->held;
        });

        $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'));

        self::assertTrue($heldDuringCall);
        self::assertFalse($this->lock->held, 'and released afterwards');
    }

    public function testBusyOrder_IsRefusedAsInProgress_AndNothingIsClaimed(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $this->lock->busy = true;

        self::assertSame('in_progress', $this->perform(AdminPaymentOperation::capture('order-1', null, 'key-capture-1'))->refusal()?->code);
        self::assertSame([], $this->idempotencyRows->records);
        self::assertSame([], $this->gateway->requests);
    }

    public function testWithoutAClientKey_TheKeyFollowsTheOrdersStateAndAmount(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'paypal', 100.0, 100.0));

        $this->perform(AdminPaymentOperation::refund('order-1', 10.0, null, null));
        $this->perform(AdminPaymentOperation::refund('order-1', 10.0, null, null));

        self::assertCount(2, $this->gateway->requests, 'the refund changed the state: the same click is a new refund');
    }

    public function testWithoutAClientKey_ADoubleSubmitOfTheSameStateIsOneCall(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));
        $this->gateway = new FakeGateway('paypal', static function (): void {
            throw new RuntimeException('timeout');
        });
        $this->perform(AdminPaymentOperation::capture('order-1', 10.0, null));
        $this->gateway = new FakeGateway('paypal');
        $this->perform(AdminPaymentOperation::capture('order-1', 10.0, null));

        self::assertCount(1, $this->gateway->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'too short' => ['abc'];
        yield 'bad characters' => ['key with spaces!'];
        yield 'too long' => [str_repeat('k', 65)];
    }

    #[DataProvider('badKeys')]
    public function testMalformedIdempotencyKey_IsRefused(string $key): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));

        self::assertSame('invalid_key', $this->perform(AdminPaymentOperation::capture('order-1', null, $key))->refusal()?->code);
        self::assertSame([], $this->gateway->requests);
    }

    // --- the provider's say on free text ----------------------------------------------------------------------

    public function testProviderWithAReasonList_RefusesAReasonOutsideIt_BeforeTheGatewayIsCalled(): void
    {
        $this->options = new AdminActionOptions(['duplicate', 'fraudulent'], [], false, 255);
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $refusal = $this->perform(AdminPaymentOperation::refund('order-1', 10.0, 'because', 'key-refund-1'))->refusal();

        self::assertSame('reason_invalid', $refusal?->code);
        self::assertSame([], $this->gateway->requests);
    }

    public function testProviderWithAReasonList_AcceptsAListedReason_AndAnOmittedOne(): void
    {
        $this->options = new AdminActionOptions(['duplicate'], [], false, 255);
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        self::assertNull($this->perform(AdminPaymentOperation::refund('order-1', 10.0, 'duplicate', 'key-refund-1'))->refusal());
        self::assertNull($this->perform(AdminPaymentOperation::refund('order-1', 10.0, null, 'key-refund-2'))->refusal());
    }

    public function testProviderLimit_IsEnforcedOnReasonAndDescription_AsTooLong(): void
    {
        $this->options = new AdminActionOptions([], [], true, 10);
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $refusal = $this->perform(AdminPaymentOperation::refund('order-1', 10.0, null, 'key-refund-1', 'a description beyond ten'))->refusal();

        self::assertSame('too_long', $refusal?->code);
        self::assertSame('description', $refusal->field);
    }

    // --- audit ------------------------------------------------------------------------------------------------

    public function testEveryCallIsAudited_WithFieldNamesAndTheAmountOnly(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));

        $this->perform(AdminPaymentOperation::refund('order-1', 12.5, 'secret reason text', 'key-refund-1', 'private note'));
        $this->perform(AdminPaymentOperation::refund('order-1', 9999.0, null, 'key-refund-2'));

        self::assertSame([
            ['operation' => 'adminPaymentRefund', 'orderId' => 'order-1', 'fields' => ['amount', 'reason', 'description'], 'amount' => 12.5, 'outcome' => 'success'],
            ['operation' => 'adminPaymentRefund', 'orderId' => 'order-1', 'fields' => ['amount'], 'amount' => 9999.0, 'outcome' => 'exceeds_refundable'],
        ], $this->audit->rows);
    }

    // --- helpers ----------------------------------------------------------------------------------------------

    private function channel(?string $deniedRight = null, bool $refuseStepUp = false): RecordingChannel
    {
        return new RecordingChannel($this->audit, $deniedRight, $refuseStepUp);
    }

    private function perform(AdminPaymentOperation $operation): PaymentOperationResult
    {
        return $this->runOn($this->channel(), $operation);
    }

    private function runOn(RecordingChannel $channel, AdminPaymentOperation $operation): PaymentOperationResult
    {
        return $this->runnerWith($this->captures)->run($operation, $channel);
    }

    private function optionsResolver(): AdminActionOptionsResolverInterface
    {
        $resolver = $this->createStub(AdminActionOptionsResolverInterface::class);
        $resolver->method('optionsFor')->willReturnCallback(fn (PaymentContractInterface $contract): AdminActionOptions => $this->options ?? AdminActionOptions::freeText());

        return $resolver;
    }

    private function runnerWith(ProviderCaptureLedgerInterface $captures): MoneyOperationRunner
    {
        $now = new DateTimeImmutable('2026-11-03 10:00:00');
        $gateways = new ProviderGatewayRegistry([$this->gateway]);
        $capturePolicy = new CapturePolicy();
        $logger = new NullLogger();
        $shop = new class ($this->orderInShop) implements RequestShopOrdersInterface {
            public function __construct(private readonly bool $contains)
            {
            }

            public function contains(string $orderId): bool
            {
                return $this->contains;
            }
        };

        return new MoneyOperationRunner(
            $this->contracts,
            new PaymentContractDetailsReader($this->contracts, $this->createStub(TransactionRepositoryInterface::class), $this->optionsResolver(), new NoActionBounds()),
            $shop,
            $this->lock,
            new OperationIdempotency($this->idempotencyRows, static fn (): DateTimeImmutable => $now),
            [
                new CaptureOperation($capturePolicy, $gateways, $captures, $logger),
                new RefundOperation(new RefundPolicy(), $gateways, $this->refunds, $logger),
                new VoidOperation($capturePolicy, $gateways, $logger),
            ],
            $logger,
        );
    }
}
