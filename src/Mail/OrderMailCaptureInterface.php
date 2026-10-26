<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mail;

/**
 * STRP-176 — a shop mailer that renders the order mails as core does, but can
 * keep them instead of sending. The payment-base Email extension is one.
 *
 * The two order-mail methods are core's, untyped as core declares them.
 *
 * @since 1.5.0
 */
interface OrderMailCaptureInterface
{
    /**
     * Every mail sent from now on is kept as $kind (DeferredOrderMail::KIND_*)
     * until takeCapturedMails().
     */
    public function captureAs(string $kind): void;

    /**
     * @return list<DeferredOrderMail> the kept mails, in the order they were sent; capturing ends
     */
    public function takeCapturedMails(): array;

    /**
     * @param mixed $order
     * @param mixed $subject
     *
     * @return bool
     */
    public function sendOrderEmailToUser($order, $subject = null);

    /**
     * @param mixed $order
     * @param mixed $subject
     *
     * @return bool
     */
    public function sendOrderEmailToOwner($order, $subject = null);
}
