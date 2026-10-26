<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mail;

/**
 * STRP-176 — a deferred order mail as read back for sending: the row id it is
 * claimed and deleted by, and the shop whose SMTP settings send it.
 *
 * @since 1.5.0
 */
final readonly class StoredOrderMail
{
    public function __construct(
        public string $id,
        public int $shopId,
        public DeferredOrderMail $mail,
    ) {
    }
}
