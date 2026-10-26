<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Mail\DeferredOrderMail;

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
}
