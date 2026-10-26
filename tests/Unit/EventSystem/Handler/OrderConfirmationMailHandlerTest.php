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
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\StoredOrderMail;
use OxidEsales\PaymentBase\Repository\DeferredOrderMailRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class FakeOrderMailStore implements DeferredOrderMailRepositoryInterface
{
    /** @var list<StoredOrderMail> */
    public array $unsent = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $heldElsewhere = [];

    public ?RuntimeException $failFind = null;

    public function save(string $orderId, int $shopId, array $mails): void
    {
    }

    public function deleteByOrderId(string $orderId): int
    {
        return 0;
    }

    public function findUnsentByOrderId(string $orderId): array
    {
        if ($this->failFind !== null) {
            throw $this->failFind;
        }
        $this->calls[] = 'find:' . $orderId;

        return $this->unsent;
    }

    public function claim(string $mailId): bool
    {
        $this->calls[] = 'claim:' . $mailId;

        return !in_array($mailId, $this->heldElsewhere, true);
    }

    public function release(string $mailId): void
    {
        $this->calls[] = 'release:' . $mailId;
    }

    public function delete(string $mailId): void
    {
        $this->calls[] = 'delete:' . $mailId;
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
 * STRP-176 — the order mails stored at early order creation go out when the
 * PSP has confirmed the payment, once, whichever leg (return or webhook)
 * commits the contract.
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

    public function testHandle_OnCommit_SendsEveryUnsentMailOfTheOrderAndDeletesIt(): void
    {
        $this->store->unsent = [$this->stored('m-1', 'customer mail'), $this->stored('m-2', 'owner mail')];

        $this->handler->handle($this->committed('order-1'));

        self::assertSame([['customer mail', 2], ['owner mail', 2]], $this->sender->sent);
        self::assertSame(
            ['find:order-1', 'claim:m-1', 'delete:m-1', 'claim:m-2', 'delete:m-2'],
            $this->store->calls
        );
    }

    public function testHandle_WhenAnotherSenderHoldsAMail_SkipsThatMail(): void
    {
        // The return leg and the webhook leg can both see the commit.
        $this->store->unsent = [$this->stored('m-1', 'customer mail'), $this->stored('m-2', 'owner mail')];
        $this->store->heldElsewhere = ['m-1'];

        $this->handler->handle($this->committed('order-1'));

        self::assertSame([['owner mail', 2]], $this->sender->sent);
        self::assertNotContains('delete:m-1', $this->store->calls);
    }

    public function testHandle_WhenNoStoredMails_DoesNothing(): void
    {
        // Orders created before this release, a replayed webhook, a stornoed order.
        $this->handler->handle($this->committed('order-1'));

        self::assertSame([], $this->sender->sent);
        self::assertSame(['find:order-1'], $this->store->calls);
    }

    public function testHandle_WhenTheMailerRefuses_KeepsTheRowReleasedAndLogsError(): void
    {
        $this->store->unsent = [$this->stored('m-1', 'customer mail'), $this->stored('m-2', 'owner mail')];
        $this->sender->outcome = ['customer mail' => false];

        $this->handler->handle($this->committed('order-1'));

        self::assertContains('release:m-1', $this->store->calls);
        self::assertNotContains('delete:m-1', $this->store->calls);
        self::assertSame([['owner mail', 2]], $this->sender->sent, 'one failed mail does not stop the next');
        self::assertContains('error', $this->logger->levels());
    }

    public function testHandle_WhenTheSenderThrows_ReleasesLogsAndDoesNotThrow(): void
    {
        // The payment is taken; a mail problem must never undo or block the commit.
        $this->store->unsent = [$this->stored('m-1', 'customer mail')];
        $this->sender->outcome = ['customer mail' => new RuntimeException('smtp down')];

        $this->handler->handle($this->committed('order-1'));

        self::assertContains('release:m-1', $this->store->calls);
        self::assertSame('error', $this->logger->records[0][0]);
        self::assertSame('m-1', $this->logger->records[0][2]['mailId']);
    }

    public function testHandle_WhenTheStoreCannotBeRead_LogsErrorAndDoesNotThrow(): void
    {
        $this->store->failFind = new RuntimeException('db down');

        $this->handler->handle($this->committed('order-1'));

        self::assertSame(['error'], $this->logger->levels());
    }

    public function testHandle_WithoutAnOrderId_DoesNothing(): void
    {
        $this->handler->handle($this->committed(''));

        self::assertSame([], $this->store->calls);
    }

    public function testHandle_IgnoresOtherEvents(): void
    {
        $this->handler->handle(new ContractDraftCompletedEvent($this->contract(), new EventContext()));

        self::assertSame([], $this->store->calls);
    }

    private function committed(string $orderId): ContractCommittedEvent
    {
        return new ContractCommittedEvent($this->contract(), new EventContext(), $orderId);
    }

    private function contract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]);

        return new PaymentContract(1, 'user-1', $snapshot, 'contract-1');
    }

    private function stored(string $id, string $subject): StoredOrderMail
    {
        return new StoredOrderMail($id, 2, new DeferredOrderMail(
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
        ));
    }
}
