<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Core;

use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\OrderMailCaptureInterface;

/**
 * STRP-176 — lets core render an order mail without sending it.
 *
 * Early order creation calls finalizeOrder() before the customer has paid at
 * the PSP, and finalizeOrder() ends by mailing "thank you for your order". The
 * Order extension switches this mailer to capturing for that call: core builds
 * the mail exactly as always (real basket, vouchers, language), and send()
 * keeps it instead of handing it to the MTA. It is sent when the contract
 * commits.
 *
 * An extension rather than a subclass, so the other modules in the Email chain
 * (BCC, extra recipients) still shape the mail that is kept.
 *
 * @since 1.5.0
 */
class Email extends Email_parent implements OrderMailCaptureInterface
{
    private ?string $captureKind = null;

    /** @var list<DeferredOrderMail> */
    private array $capturedMails = [];

    /**
     * @inheritDoc
     */
    public function captureAs(string $kind): void
    {
        $this->captureKind = $kind;
    }

    /**
     * @inheritDoc
     */
    public function takeCapturedMails(): array
    {
        $mails = $this->capturedMails;
        $this->capturedMails = [];
        $this->captureKind = null;

        return $mails;
    }

    /**
     * No native return type, for the reason Order::cancelOrder() gives: core
     * declares none, and the first extension in the chain to add one breaks
     * every other module that extends send().
     *
     * @return bool
     */
    public function send()
    {
        if ($this->captureKind === null) {
            return parent::send();
        }

        // Core's send() refuses a mail without recipient; so does the capture,
        // so finalizeOrder() still reports ORDER_STATE_MAILINGERROR for it.
        $recipients = $this->addressPairs($this->getRecipient());
        if ($recipients === []) {
            return false;
        }

        $this->capturedMails[] = new DeferredOrderMail(
            kind: $this->captureKind,
            from: (string) $this->getFrom(),
            fromName: (string) $this->getFromName(),
            recipients: $recipients,
            replyTo: $this->addressPairs($this->getReplyTo()),
            cc: $this->addressPairs($this->getCcAddresses()),
            bcc: $this->addressPairs($this->getBccAddresses()),
            subject: (string) $this->getSubject(),
            body: (string) $this->getBody(),
            altBody: (string) $this->getAltBody(),
        );

        return true;
    }

    /**
     * Core and PHPMailer both keep addresses as `[address, name]` lists.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function addressPairs(mixed $addresses): array
    {
        if (!is_array($addresses)) {
            return [];
        }

        $pairs = [];
        foreach ($addresses as $address) {
            if (is_array($address) && isset($address[0]) && (string) $address[0] !== '') {
                $pairs[] = [(string) $address[0], (string) ($address[1] ?? '')];
            }
        }

        return $pairs;
    }
}
