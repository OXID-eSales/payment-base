<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopMailSenderInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\OrderConfirmationMailHandler;
use OxidEsales\PaymentBase\Mail\ClaimedOrderMails;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Repository\ContractOrderMailStoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class FakeOrderMailStore implements ContractOrderMailStoreInterface
{
    /** @var list<DeferredOrderMail> what claim() hands out; [] = nothing to claim */
    public array $pending = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> subjects passed to markFailed() */
    public array $keptForResend = [];

    public ?RuntimeException $failClaim = null;

    public function stash(string $contractId, array $mails): bool
    {
        return true;
    }

    public function claim(string $contractId): ?ClaimedOrderMails
    {
        if ($this->failClaim !== null) {
            throw $this->failClaim;
        }
        $this->calls[] = 'claim:' . $contractId;
        if ($this->pending === []) {
            return null;
        }
        $mails = $this->pending;
        $this->pending = [];

        return new ClaimedOrderMails($contractId, 'order-1', 2, $mails);
    }

    public function markSent(string $contractId): void
    {
        $this->calls[] = 'sent:' . $contractId;
    }

    public function markFailed(string $contractId, array $unsent): void
    {
        $this->calls[] = 'failed:' . $contractId;
        $this->keptForResend = array_map(static fn (DeferredOrderMail $mail): string => $mail->subject, $unsent);
    }

    public function dropByOrderId(string $orderId): int
    {
        return 0;
    }
}

final class FakeMailSender implements ShopMailSenderInterface
{
    /** @var list<array{0: string, 1: int}> */
    public array $sent = [];

    /** @var array<string, bool|RuntimeException> by subject */
    public array $outcome = [];

    public function send(DeferredOrderMail $mail, int $shopId): bool
    {
        $outcome = $this->outcome[$mail->subject] ?? true;
        if ($outcome instanceof RuntimeException) {
            throw $outcome;
        }
        if ($outcome) {
            $this->sent[] = [$mail->subject, $shopId];
        }

        return $outcome;
    }
}

final class LevelLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }

    /**
     * @return list<mixed>
     */
    public function levels(): array
    {
        return array_column($this->records, 0);
    }
}

/**
 * STRP-176 — the order mails kept on the contract at early order creation go
 * out when the PSP has confirmed the payment, once, whichever leg (return or
 * webhook) commits the contract; the contract records the outcome.
 */
final class OrderConfirmationMailHandlerTest extends TestCase
{
    private FakeOrderMailStore $store;
    private FakeMailSender $sender;
    private LevelLogger $logger;
    private OrderConfirmationMailHandler $handler;

    protected function setUp(): void
    {
        $this->store = new FakeOrderMailStore();
        $this->sender = new FakeMailSender();
        $this->logger = new LevelLogger();
        $this->handler = new OrderConfirmationMailHandler($this->store, $this->sender, $this->logger);
    }

    public function testHandlesContractCommitted(): void
    {
        self::assertInstanceOf(HandlerInterface::class, $this->handler);
        self::assertSame(ContractCommittedEvent::class, OrderConfirmationMailHandler::getHandledEventClass());
    }

    public function testHandle_OnCommit_ClaimsSendsBothWithTheContractsShopAndMarksSent(): void
    {
        $this->store->pending = [$this->mail('customer mail'), $this->mail('owner mail')];

        $this->handler->handle($this->committed());

        self::assertSame([['customer mail', 2], ['owner mail', 2]], $this->sender->sent);
        self::assertSame(['claim:contract-1', 'sent:contract-1'], $this->store->calls);
    }

    public function testHandle_WhenThereIsNothingToClaim_SendsNothing(): void
    {
        // Nothing deferred (order from before 1.5.0), the other leg holds the
        // mails, a replayed webhook, or a stornoed order.
        $this->handler->handle($this->committed());

        self::assertSame([], $this->sender->sent);
        self::assertSame(['claim:contract-1'], $this->store->calls);
    }

    public function testHandle_WhenOneMailIsRefused_KeepsOnlyThatOneAsFailedAndLogsError(): void
    {
        $this->store->pending = [$this->mail('customer mail'), $this->mail('owner mail')];
        $this->sender->outcome = ['owner mail' => false];

        $this->handler->handle($this->committed());

        self::assertSame([['customer mail', 2]], $this->sender->sent);
        self::assertSame(['claim:contract-1', 'failed:contract-1'], $this->store->calls);
        self::assertSame(['owner mail'], $this->store->keptForResend, 'never the mail the customer already has');
        self::assertContains('error', $this->logger->levels());
    }

    public function testHandle_WhenTheSenderThrows_MarksFailedLogsAndDoesNotThrow(): void
    {
        // The payment is taken; a mail problem must never undo or block the commit.
        $this->store->pending = [$this->mail('customer mail'), $this->mail('owner mail')];
        $this->sender->outcome = ['customer mail' => new RuntimeException('smtp down')];

        $this->handler->handle($this->committed());

        self::assertSame([['owner mail', 2]], $this->sender->sent, 'one failed mail does not stop the next');
        self::assertSame(['customer mail'], $this->store->keptForResend);
        self::assertSame('error', $this->logger->records[0][0]);
        self::assertSame('contract-1', $this->logger->records[0][2]['contractId']);
    }

    public function testHandle_WhenTheStoreFails_LogsErrorAndDoesNotThrow(): void
    {
        $this->store->failClaim = new RuntimeException('db down');

        $this->handler->handle($this->committed());

        self::assertSame(['error'], $this->logger->levels());
    }

    public function testHandle_IgnoresOtherEvents(): void
    {
        $this->handler->handle(new ContractDraftCompletedEvent($this->contract(), new EventContext()));

        self::assertSame([], $this->store->calls);
    }

    private function committed(): ContractCommittedEvent
    {
        return new ContractCommittedEvent($this->contract(), new EventContext(), 'order-1');
    }

    private function contract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]);

        return new PaymentContract(1, 'user-1', $snapshot, 'contract-1');
    }

    private function mail(string $subject): DeferredOrderMail
    {
        return new DeferredOrderMail(
            kind: DeferredOrderMail::KIND_CUSTOMER,
            from: 'order@shop.test',
            fromName: 'My Shop',
            recipients: [['jane@example.test', 'Jane Doe']],
            replyTo: [],
            cc: [],
            bcc: [],
            subject: $subject,
            body: '<p>Thank you</p>',
            altBody: 'Thank you',
        );
    }
}
