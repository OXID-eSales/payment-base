<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Application\Model;

use OxidEsales\PaymentBase\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Eshop\Application\Model\Order_parent;
use OxidEsales\PaymentBase\Eshop\Core\Email;
use OxidEsales\PaymentBase\Eshop\Core\Email_parent;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\OrderMailCaptureInterface;
use OxidEsales\PaymentBase\Repository\DeferredOrderMailRepositoryInterface;
use OxidEsales\PaymentBase\Repository\VoucherReleaseInterface;
use OxidEsales\PaymentBase\Service\VoucherReleaseSettingsInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

/**
 * STRP-176 — the order mail of a PSP checkout is rendered when the order is
 * created, but only kept: the customer has not paid yet.
 */
final class OrderMailDeferralTest extends TestCase
{
    private SpyDeferredOrderMails $store;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        Order_parent::$calls = [];
        Order_parent::$deleteResult = true;
        Email_parent::$calls = [];
        $this->store = new SpyDeferredOrderMails();
        $this->logger = new RecordingLogger();
    }

    public function testSendOrderByEmail_WhenNotDeferred_DelegatesToParent(): void
    {
        $order = $this->order();

        self::assertSame(Order::ORDER_STATE_OK, $order->callSendOrderByEmail());

        self::assertSame(['parent::sendOrderByEmail'], Order_parent::$calls);
        self::assertSame([], $this->store->saved);
    }

    public function testSendOrderByEmail_WhenDeferred_StoresCustomerAndOwnerMailAndSendsNothing(): void
    {
        $order = $this->order();
        $order->deferConfirmationMail();

        $order->callSendOrderByEmail();

        self::assertSame([], Order_parent::$calls, 'core sendOrderByEmail() would mail now');
        self::assertSame([], Email_parent::$calls, 'nothing reaches the MTA');
        self::assertCount(1, $this->store->saved);
        [$orderId, $shopId, $mails] = $this->store->saved[0];
        self::assertSame('order-1', $orderId);
        self::assertSame(1, $shopId);
        self::assertSame(
            [DeferredOrderMail::KIND_CUSTOMER, DeferredOrderMail::KIND_OWNER],
            array_map(static fn (DeferredOrderMail $mail): string => $mail->kind, $mails)
        );
    }

    public function testSendOrderByEmail_WhenDeferred_ReturnsStateOk(): void
    {
        $order = $this->order();
        $order->deferConfirmationMail();

        self::assertSame(Order::ORDER_STATE_OK, $order->callSendOrderByEmail());
    }

    public function testSendOrderByEmail_WhenDeferred_HandsTheTemplatesUserBasketAndPayment(): void
    {
        // Core assigns these before rendering; order_cust reads order.getBasket().
        $user = new \stdClass();
        $basket = new \stdClass();
        $payment = new \stdClass();
        $order = $this->order();
        $order->deferConfirmationMail();

        $order->callSendOrderByEmail($user, $basket, $payment);

        self::assertSame($user, $order->_oUser);
        self::assertSame($basket, $order->_oBasket);
        self::assertSame($payment, $order->_oPayment);
    }

    public function testSendOrderByEmail_WhenDeferredAndCustomerMailCannotBeBuilt_ReturnsMailingError(): void
    {
        // Same answer core gives when the customer mail fails.
        $mailer = new RenderingMailer();
        $mailer->recipients = [];
        $order = $this->order($mailer);
        $order->deferConfirmationMail();

        self::assertSame(Order::ORDER_STATE_MAILINGERROR, $order->callSendOrderByEmail());
    }

    public function testSendOrderByEmail_WhenStoringFails_LogsErrorAndReturnsMailingError(): void
    {
        $this->store->failWith = new RuntimeException('db down');
        $order = $this->order();
        $order->deferConfirmationMail();

        self::assertSame(Order::ORDER_STATE_MAILINGERROR, $order->callSendOrderByEmail());

        self::assertSame('error', $this->logger->records[0][0]);
        self::assertStringContainsString('order-1', (string) json_encode($this->logger->records[0][2]));
    }

    public function testSendOrderByEmail_WhenEmailExtensionIsNotInTheChain_SendsNowAndWarns(): void
    {
        // Better an early mail than none: without the extension nothing could
        // keep it, and nothing would send it later.
        $order = $this->order(mailer: null);
        $order->deferConfirmationMail();

        self::assertSame(Order::ORDER_STATE_OK, $order->callSendOrderByEmail());

        self::assertSame(['parent::sendOrderByEmail'], Order_parent::$calls);
        self::assertSame('warning', $this->logger->records[0][0]);
    }

    public function testDelete_RemovesStoredMailsOfTheOrder(): void
    {
        $this->order()->delete();

        self::assertSame(['order-1'], $this->store->deleted);
    }

    public function testDelete_WithExplicitId_RemovesStoredMailsOfThatOrder(): void
    {
        $this->order()->delete('order-7');

        self::assertSame(['order-7'], $this->store->deleted);
    }

    public function testDelete_WhenCoreRefuses_KeepsStoredMails(): void
    {
        Order_parent::$deleteResult = false;

        $this->order()->delete();

        self::assertSame([], $this->store->deleted);
    }

    public function testDelete_WhenRemovingMailsFails_StillReportsTheDeleteAndLogs(): void
    {
        $this->store->failWith = new RuntimeException('db down');

        self::assertTrue($this->order()->delete());
        self::assertSame('error', $this->logger->records[0][0]);
    }

    public function testCancelOrder_RemovesStoredMails(): void
    {
        // A cancelled order is never confirmed - its mail must not go out later.
        $this->order()->cancelOrder();

        self::assertSame(['order-1'], $this->store->deleted);
    }

    private function order(?OrderMailCaptureInterface $mailer = new RenderingMailer()): TestableMailOrder
    {
        return new TestableMailOrder($this->store, $mailer, $this->logger);
    }
}

/**
 * Stands in for the Email chain: the two order-mail methods end in send(),
 * as core's do, after "rendering" a fixed mail.
 */
final class RenderingMailer extends Email
{
    public function __construct()
    {
        $this->From = 'order@shop.test';
        $this->recipients = [['jane@example.test', 'Jane Doe']];
        $this->Subject = 'Your order (#42)';
        $this->Body = '<p>Thank you</p>';
    }
}

final class SpyDeferredOrderMails implements DeferredOrderMailRepositoryInterface
{
    /** @var list<array{0: string, 1: int, 2: list<DeferredOrderMail>}> */
    public array $saved = [];

    /** @var list<string> */
    public array $deleted = [];

    public ?RuntimeException $failWith = null;

    public function save(string $orderId, int $shopId, array $mails): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->saved[] = [$orderId, $shopId, $mails];
    }

    public function deleteByOrderId(string $orderId): int
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->deleted[] = $orderId;

        return 2;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string|Stringable, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }
}

final class NoVoucherRelease implements VoucherReleaseInterface, VoucherReleaseSettingsInterface
{
    public function releaseVouchers(string $orderId): int
    {
        return 0;
    }

    public function isReleaseOnOrderEndEnabled(): bool
    {
        return false;
    }
}

final class TestableMailOrder extends Order
{
    public function __construct(
        private readonly DeferredOrderMailRepositoryInterface $store,
        private readonly ?OrderMailCaptureInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
        // No parent::__construct(): BaseModel needs a shop.
    }

    public function callSendOrderByEmail(mixed $user = null, mixed $basket = null, mixed $payment = null): int
    {
        return $this->sendOrderByEmail($user, $basket, $payment);
    }

    protected function getDeferredOrderMails(): DeferredOrderMailRepositoryInterface
    {
        return $this->store;
    }

    protected function newOrderMailer(): ?OrderMailCaptureInterface
    {
        return $this->mailer;
    }

    protected function getOrderMailLogger(): LoggerInterface
    {
        return $this->logger;
    }

    protected function getVoucherRelease(): VoucherReleaseInterface
    {
        return new NoVoucherRelease();
    }

    protected function getVoucherReleaseSettings(): VoucherReleaseSettingsInterface
    {
        return new NoVoucherRelease();
    }
}
