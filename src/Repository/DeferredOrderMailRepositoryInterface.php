<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\StoredOrderMail;

/**
 * STRP-176 — where the order mails of an unpaid order wait for the payment.
 *
 * The rows hold the customer's name, address and articles (the same data the
 * order row holds), so they live only as long as the order can still be paid:
 * they go once sent, and with the order when it is deleted or cancelled.
 *
 * @since 1.5.0
 */
interface DeferredOrderMailRepositoryInterface
{
    /**
     * @param list<DeferredOrderMail> $mails
     */
    public function save(string $orderId, int $shopId, array $mails): void;

    /**
     * @return int how many mails were removed
     */
    public function deleteByOrderId(string $orderId): int;

    /**
     * The mails of $orderId nobody is sending, as long as the order is still in
     * oxorder and not stornoed: a cancelled order is never confirmed, not even
     * when a late payment commits its contract.
     *
     * @return list<StoredOrderMail>
     */
    public function findUnsentByOrderId(string $orderId): array;

    /**
     * Takes one mail for sending. False when another sender (the other leg of
     * the same payment) already holds it.
     */
    public function claim(string $mailId): bool;

    /**
     * Gives a claimed mail back, after its send failed.
     */
    public function release(string $mailId): void;

    /**
     * Removes one mail, once sent.
     */
    public function delete(string $mailId): void;
}
