<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mail;

/**
 * STRP-176 — the order mails of one contract, taken for sending: the shop
 * whose SMTP settings send them, and the order they belong to (for the log).
 *
 * @since 1.6.0
 */
final readonly class ClaimedOrderMails
{
    /**
     * @param list<DeferredOrderMail> $mails customer before owner
     */
    public function __construct(
        public string $contractId,
        public string $orderId,
        public int $shopId,
        public array $mails,
    ) {
    }
}
