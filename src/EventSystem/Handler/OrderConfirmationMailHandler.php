<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopMailSenderInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\Mail\StoredOrderMail;
use OxidEsales\PaymentBase\Repository\DeferredOrderMailRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * STRP-176 — "thank you for your order" goes out once the PSP has confirmed
 * the payment.
 *
 * Early order creation stores the customer and owner mail core rendered (see
 * the Order extension). The commit is the moment the payment is confirmed, on
 * the return leg or the webhook leg, whichever is first; each stored mail is
 * claimed before it is sent, so the other leg - or a replayed webhook - cannot
 * send it again. A mail that fails stays stored, released, and is logged: the
 * payment is taken, and a mail problem must never undo or block the commit.
 *
 * @since 1.5.0
 */
class OrderConfirmationMailHandler implements HandlerInterface
{
    public function __construct(
        private readonly DeferredOrderMailRepositoryInterface $mails,
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
        if (!$event instanceof ContractCommittedEvent || $event->getOrderId() === '') {
            return;
        }

        $orderId = $event->getOrderId();
        try {
            $unsent = $this->mails->findUnsentByOrderId($orderId);
        } catch (Throwable $e) {
            $this->logger->error('[OrderConfirmationMailHandler] stored order mails could not be read', [
                'orderId' => $orderId,
                'contractId' => $event->getContractId(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($unsent as $stored) {
            $this->sendOnce($stored, $orderId);
        }
    }

    private function sendOnce(StoredOrderMail $stored, string $orderId): void
    {
        $context = ['orderId' => $orderId, 'mailId' => $stored->id, 'kind' => $stored->mail->kind];

        try {
            if (!$this->mails->claim($stored->id)) {
                // The other leg of the same payment is sending it.
                return;
            }

            if (!$this->sender->send($stored->mail, $stored->shopId)) {
                $this->giveBack($stored, $context, 'the shop mailer refused the order mail');

                return;
            }

            $this->mails->delete($stored->id);
            $this->logger->info('[OrderConfirmationMailHandler] order mail sent after payment', $context);
        } catch (Throwable $e) {
            $this->giveBack($stored, $context + ['error' => $e->getMessage()], 'order mail not sent');
        }
    }

    /**
     * @param array<string, string> $context
     */
    private function giveBack(StoredOrderMail $stored, array $context, string $message): void
    {
        $this->logger->error('[OrderConfirmationMailHandler] ' . $message . ', kept for a resend', $context);

        try {
            $this->mails->release($stored->id);
        } catch (Throwable) {
            // Logged above; a claim left set only keeps it from a second send.
        }
    }
}
