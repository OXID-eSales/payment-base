<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mail;

/**
 * STRP-176 — one order mail exactly as core rendered it, before it was sent.
 *
 * Early order creation runs finalizeOrder() before the customer has paid at
 * the PSP. The mail is rendered there - with the real basket, vouchers and
 * language of the customer's own request - and kept in this shape until the
 * contract commits. Rebuilding it later from the saved order gives the wrong
 * totals (see the STRP-176 spike report), so only the finished mail travels.
 *
 * Addresses keep core's `[address, name]` pairs; the body is taken before
 * Email::send() embeds inline images, which the replaying send() does again.
 *
 * @since 1.5.0
 */
final readonly class DeferredOrderMail
{
    public const KIND_CUSTOMER = 'customer';
    public const KIND_OWNER = 'owner';

    /**
     * @param string $kind self::KIND_CUSTOMER or self::KIND_OWNER
     * @param list<array{0: string, 1: string}> $recipients
     * @param list<array{0: string, 1: string}> $replyTo
     * @param list<array{0: string, 1: string}> $cc
     * @param list<array{0: string, 1: string}> $bcc
     */
    public function __construct(
        public string $kind,
        public string $from,
        public string $fromName,
        public array $recipients,
        public array $replyTo,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $body,
        public string $altBody,
    ) {
    }
}
