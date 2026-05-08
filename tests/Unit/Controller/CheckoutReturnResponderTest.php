<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\SessionWriterInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Return\CheckoutReturnCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sprint E: the `CheckoutReturnResponder` is the one place the
 * provider-neutral post-return steps live:
 *   1. build an EventContext carrying providerName + contract + any
 *      PSP-specific extras the caller passed in;
 *   2. call the resolver to translate the PSP outcome into a
 *      `ReturnResolution`;
 *   3. on success, dispatch `CheckoutReturnCompletedEvent` +
 *      `PaymentAuthorizedEvent` so the shared handler chain commits
 *      the contract;
 *   4. return the orderId the shared handlers wrote into the context
 *      (or null on failure), so each controller can translate to its
 *      own template / redirect convention.
 *
 * The responder has no knowledge of Stripe, PayPal, or OPC. All three
 * controllers call it the same way.
 */
#[CoversClass(CheckoutReturnResponder::class)]
final class CheckoutReturnResponderTest extends TestCase
{
    private EventDispatcherInterface&MockObject $dispatcher;
    private ReturnResolverInterface&MockObject $resolver;
    private PaymentContractInterface&MockObject $contract;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->resolver = $this->createMock(ReturnResolverInterface::class);
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contr_1');
        $this->contract->method('getOrderId')->willReturn('order_fallback');
    }

    public function testSuccessDispatchesCheckoutReturnCompletedAndPaymentAuthorized(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', 42.0, 'EUR'),
        );

        $events = [];
        $this->dispatcher->method('dispatch')->willReturnCallback(
            static function (object $e) use (&$events): object {
                $events[] = $e;
                return $e;
            }
        );

        $responder = $this->buildResponder();
        $orderId = $responder->respond('stripe', $this->contract, $this->resolver, []);

        self::assertNotNull($orderId);
        self::assertCount(2, $events);
        self::assertInstanceOf(CheckoutReturnCompletedEvent::class, $events[0]);
        self::assertInstanceOf(PaymentAuthorizedEvent::class, $events[1]);
    }

    public function testContextCarriesProviderNameAndExtraKeys(): void
    {
        $this->resolver->method('resolve')->willReturnCallback(
            function ($contract, $context): ReturnResolution {
                self::assertSame('paypal', $context->get('providerName'));
                self::assertSame('sess_abc', $context->get('checkoutSessionId'));
                self::assertSame('contr_1', $context->get('contract_id'));
                self::assertSame($this->contract, $context->getContract());
                return ReturnResolution::readyToCommit('a', 'p', 1.0, 'EUR');
            }
        );

        $this->buildResponder()->respond(
            'paypal',
            $this->contract,
            $this->resolver,
            ['checkoutSessionId' => 'sess_abc'],
        );
    }

    public function testFailedResolutionReturnsNullAndSkipsDispatch(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::failed('declined', 'Card was declined'),
        );
        $this->dispatcher->expects(self::never())->method('dispatch');

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertNull($orderId);
    }

    public function testReadsOrderIdFromContextBeforeFallingBackToContract(): void
    {
        // ContractCommitmentHandler writes the orderId into the context
        // when it commits. The responder must prefer that.
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('auth_1', 'ord_1', 1.0, 'EUR'),
        );
        $this->dispatcher->method('dispatch')->willReturnCallback(
            function (object $e) {
                if ($e instanceof CheckoutReturnCompletedEvent) {
                    $e->getContext()->set('orderId', 'order_from_context');
                }
                return $e;
            }
        );

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertSame('order_from_context', $orderId);
    }

    public function testFallsBackToContractOrderIdWhenContextHasNone(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::readyToCommit('a', 'p', 1.0, 'EUR'),
        );

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertSame('order_fallback', $orderId);
    }

    public function testResolverExceptionBubblesAsNullNoLeakingStackTrace(): void
    {
        $this->resolver->method('resolve')->willThrowException(new RuntimeException('psp down'));
        $this->dispatcher->expects(self::never())->method('dispatch');

        $orderId = $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertNull($orderId);
    }

    public function testRequiresCaptureFromResolutionPropagatesIntoContext(): void
    {
        $this->resolver->method('resolve')->willReturn(
            ReturnResolution::authorized('auth_1', 'ord_1', 1.0, 'EUR'),
        );

        $captured = null;
        $this->dispatcher->method('dispatch')->willReturnCallback(
            function (object $e) use (&$captured): object {
                if ($e instanceof CheckoutReturnCompletedEvent && $captured === null) {
                    $captured = $e->getContext()->get('requiresCapture');
                }
                return $e;
            }
        );

        $this->buildResponder()->respond(
            'stripe', $this->contract, $this->resolver, []
        );

        self::assertTrue($captured, 'requiresCapture should be set from the resolution');
    }

    private function buildResponder(): CheckoutReturnResponder
    {
        // The real writer uses OXID's Registry::getSession(); tests
        // only care about dispatch + context + orderId return, so pass
        // a no-op writer.
        $writer = new class implements SessionWriterInterface {
            public function writeSessChallenge(string $orderId): void
            {
            }
        };
        return new CheckoutReturnResponder($this->dispatcher, $writer);
    }
}
