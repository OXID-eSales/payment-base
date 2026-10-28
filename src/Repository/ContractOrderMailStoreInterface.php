<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Mail\ClaimedOrderMails;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;

/**
 * STRP-176 — the order mails of an unpaid order wait on its contract row, and
 * the row records whether and when they went out.
 *
 * Kept apart from the PaymentContract entity on purpose: the mails hold the
 * customer's name, address and articles, and must not travel with the
 * contract into toArray(), the admin API or a log line. A contract save()
 * never writes these columns, and this store never bumps OXVERSION.
 *
 * The mails live only as long as the order can still be paid: they are
 * cleared once sent, and when the order is cancelled or deleted.
 *
 * @since 1.6.0
 */
interface ContractOrderMailStoreInterface
{
    public const STATE_PENDING = 'pending';
    public const STATE_SENDING = 'sending';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';
    public const STATE_DROPPED = 'dropped';

    /**
     * Keeps the mails rendered at order creation on the contract, as pending.
     *
     * @param list<DeferredOrderMail> $mails
     *
     * @return bool false when there is no such contract row
     */
    public function stash(string $contractId, array $mails): bool;

    /**
     * Takes the pending mails of the contract for sending. Null when there is
     * nothing to send: nothing was deferred, the other leg of the same payment
     * already holds them, they went out already, or the order was cancelled -
     * a cancelled order is never confirmed, not even by a late payment.
     */
    public function claim(string $contractId): ?ClaimedOrderMails;

    /**
     * All claimed mails went out: the content goes, the status and time stay.
     */
    public function markSent(string $contractId): void;

    /**
     * Some claimed mails did not go out. Only those are kept, for a resend by
     * hand - never the ones the customer already has.
     *
     * @param list<DeferredOrderMail> $unsent
     */
    public function markFailed(string $contractId, array $unsent): void;

    /**
     * The order ended before it was paid: its mails must never go out.
     * Leaves mails already sent or being sent alone.
     *
     * @return int how many contracts had mails dropped
     */
    public function dropByOrderId(string $orderId): int;
}
