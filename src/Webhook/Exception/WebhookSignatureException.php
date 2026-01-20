<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook\Exception;

use Exception;

/**
 * Exception thrown when webhook signature verification fails.
 *
 * Used by AbstractWebhookProcessor to indicate that the incoming
 * webhook request could not be authenticated.
 *
 * @since Sprint 5
 */
class WebhookSignatureException extends Exception
{
    public function __construct(string $message = 'Invalid webhook signature', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
