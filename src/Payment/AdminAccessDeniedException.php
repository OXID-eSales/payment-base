<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use RuntimeException;

/**
 * The admin may not do this (right missing) or has not proven who they are recently enough (step-up). Thrown through
 * the audit, so a denied attempt leaves its row. The code is what the door shows or answers; the message is a sentence
 * for the admin.
 *
 * @since 1.7.0
 */
final class AdminAccessDeniedException extends RuntimeException
{
    public function __construct(private readonly string $denialCode, string $message)
    {
        parent::__construct($message);
    }

    public function denialCode(): string
    {
        return $this->denialCode;
    }
}
