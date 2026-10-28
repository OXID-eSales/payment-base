<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopMailSenderInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\Mail\ClaimedOrderMails;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Repository\ContractOrderMailStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * STRP-176 — "thank you for your order" goes out once the PSP has confirmed
 * the payment.
 *
 * Early order creation keeps the customer and owner mail core rendered on the
 * contract (see OxidShopOrderService). The commit is the moment the payment is
 * confirmed, on the return leg or the webhook leg, whichever is first; the
 * mails are claimed on the contract row before they are sent, so the other
 * leg - or a replayed webhook - cannot send them again. The row then records
 * "sent" and when. A mail that fails is kept there as "failed" and logged:
 * the payment is taken, and a mail problem must never undo or block the
 * commit.
 *
 * @since 1.5.0
 */
class OrderConfirmationMailHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContractOrderMailStoreInterface $mails,
        private readonly ShopMailSenderInterface $sender,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractCommittedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractCommittedEvent || $event->getContractId() === '') {
            return;
        }

        $context = ['contractId' => $event->getContractId(), 'orderId' => $event->getOrderId()];
        try {
            $claimed = $this->mails->claim($event->getContractId());
            if ($claimed === null) {
                // Nothing deferred, the other leg holds them, already sent, or
                // the order was cancelled.
                return;
            }

            $this->sendAll($claimed);
        } catch (Throwable $e) {
            $this->logger->error(
                '[OrderConfirmationMailHandler] order mails not sent',
                $context + ['error' => $e->getMessage()]
            );
        }
    }

    private function sendAll(ClaimedOrderMails $claimed): void
    {
        $context = ['contractId' => $claimed->contractId, 'orderId' => $claimed->orderId];
        $unsent = [];
        foreach ($claimed->mails as $mail) {
            if (!$this->sendOne($mail, $claimed->shopId, $context)) {
                $unsent[] = $mail;
            }
        }

        if ($unsent !== []) {
            $this->mails->markFailed($claimed->contractId, $unsent);
            $this->logger->error('[OrderConfirmationMailHandler] order mails kept on the contract for a resend', $context
                + ['kinds' => array_map(static fn (DeferredOrderMail $mail): string => $mail->kind, $unsent)]);

            return;
        }

        $this->mails->markSent($claimed->contractId);
        $this->logger->info('[OrderConfirmationMailHandler] order mails sent after payment', $context);
    }

    /**
     * @param array<string, string> $context
     */
    private function sendOne(DeferredOrderMail $mail, int $shopId, array $context): bool
    {
        try {
            if ($this->sender->send($mail, $shopId)) {
                return true;
            }
            $this->logger->error('[OrderConfirmationMailHandler] the shop mailer refused the order mail', $context
                + ['kind' => $mail->kind]);
        } catch (Throwable $e) {
            $this->logger->error('[OrderConfirmationMailHandler] order mail not sent', $context
                + ['kind' => $mail->kind, 'error' => $e->getMessage()]);
        }

        return false;
    }
}
