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
use OxidEsales\PaymentBase\Mail\ClaimedOrderMails;
use OxidEsales\PaymentBase\Repository\ContractOrderMailStoreInterface;
use OxidEsales\PaymentBase\Repository\VoucherReleaseInterface;
use OxidEsales\PaymentBase\Service\VoucherReleaseSettingsInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

/**
 * STRP-176 — the order mail of a PSP checkout is rendered when the order is
 * created, but only kept: the customer has not paid yet. The order holds it
 * until the order creation hands it to the contract.
 */
final class OrderMailDeferralTest extends TestCase
{
    private SpyContractOrderMails $store;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        Order_parent::$calls = [];
        Order_parent::$deleteResult = true;
        Email_parent::$calls = [];
        $this->store = new SpyContractOrderMails();
        $this->logger = new RecordingLogger();
    }

    public function testSendOrderByEmail_WhenNotDeferred_DelegatesToParent(): void
    {
        $order = $this->order();

        self::assertSame(Order::ORDER_STATE_OK, $order->callSendOrderByEmail());

        self::assertSame(['parent::sendOrderByEmail'], Order_parent::$calls);
        self::assertSame([], $order->takeDeferredMails());
    }

    public function testSendOrderByEmail_WhenDeferred_KeepsCustomerAndOwnerMailAndSendsNothing(): void
    {
        $order = $this->order();
        $order->deferConfirmationMail();

        $order->callSendOrderByEmail();

        self::assertSame([], Order_parent::$calls, 'core sendOrderByEmail() would mail now');
        self::assertSame([], Email_parent::$calls, 'nothing reaches the MTA');
        self::assertSame(
            [DeferredOrderMail::KIND_CUSTOMER, DeferredOrderMail::KIND_OWNER],
            array_map(static fn (DeferredOrderMail $mail): string => $mail->kind, $order->takeDeferredMails())
        );
        self::assertSame([], $this->store->dropped, 'the order stores nothing itself');
    }

    public function testTakeDeferredMails_HandsThemOverOnce(): void
    {
        $order = $this->order();
        $order->deferConfirmationMail();
        $order->callSendOrderByEmail();

        $order->takeDeferredMails();

        self::assertSame([], $order->takeDeferredMails());
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

    /**
     * OxidShopOrderService refuses every state but OK, so MAILINGERROR here
     * would abort a valid checkout over a mail the customer gets only after
     * paying anyway. The order goes on; the gap is logged.
     */
    public function testSendOrderByEmail_WhenDeferredAndTheCustomerMailCannotBeBuilt_ReturnsOkAndLogsError(): void
    {
        $mailer = new RenderingMailer();
        $mailer->recipients = [];
        $order = $this->order($mailer);
        $order->deferConfirmationMail();

        self::assertSame(Order::ORDER_STATE_OK, $order->callSendOrderByEmail());

        self::assertSame('error', $this->logger->records[0][0]);
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

    public function testDelete_DropsTheWaitingMailsOfTheOrder(): void
    {
        $this->order()->delete();

        self::assertSame(['order-1'], $this->store->dropped);
    }

    public function testDelete_WithExplicitId_DropsTheWaitingMailsOfThatOrder(): void
    {
        $this->order()->delete('order-7');

        self::assertSame(['order-7'], $this->store->dropped);
    }

    public function testDelete_WhenCoreRefuses_KeepsTheWaitingMails(): void
    {
        Order_parent::$deleteResult = false;

        $this->order()->delete();

        self::assertSame([], $this->store->dropped);
    }

    public function testDelete_WhenDroppingMailsFails_StillReportsTheDeleteAndLogs(): void
    {
        $this->store->failWith = new RuntimeException('db down');

        self::assertTrue($this->order()->delete());
        self::assertSame('error', $this->logger->records[0][0]);
    }

    public function testCancelOrder_DropsTheWaitingMails(): void
    {
        // A cancelled order is never confirmed - its mail must not go out later.
        $this->order()->cancelOrder();

        self::assertSame(['order-1'], $this->store->dropped);
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

final class SpyContractOrderMails implements ContractOrderMailStoreInterface
{
    /** @var list<string> */
    public array $dropped = [];

    public ?RuntimeException $failWith = null;

    public function stash(string $contractId, array $mails): bool
    {
        return true;
    }

    public function claim(string $contractId): ?ClaimedOrderMails
    {
        return null;
    }

    public function markSent(string $contractId): void
    {
    }

    public function markFailed(string $contractId, array $unsent): void
    {
    }

    public function dropByOrderId(string $orderId): int
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->dropped[] = $orderId;

        return 1;
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
        private readonly ContractOrderMailStoreInterface $store,
        private readonly ?OrderMailCaptureInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
        // No parent::__construct(): BaseModel needs a shop.
    }

    public function callSendOrderByEmail(mixed $user = null, mixed $basket = null, mixed $payment = null): int
    {
        return $this->sendOrderByEmail($user, $basket, $payment);
    }

    protected function getContractOrderMails(): ContractOrderMailStoreInterface
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
