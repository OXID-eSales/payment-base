<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mail;

/**
 * STRP-176 — an order that can hold its confirmation mail back until the
 * payment is confirmed. The payment-base Order extension is one; the order
 * creation adapter only needs to know this much of it.
 *
 * @since 1.5.0
 */
interface DefersConfirmationMailInterface
{
    /**
     * The next finalizeOrder() renders the order mails but keeps them instead
     * of sending them.
     */
    public function deferConfirmationMail(): void;

    /**
     * What that finalizeOrder() rendered, handed over once (since 1.6.0): the
     * order creation keeps it on the contract until the payment is confirmed.
     *
     * @return list<DeferredOrderMail>
     */
    public function takeDeferredMails(): array;
}
