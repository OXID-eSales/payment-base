<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * Why an admin payment operation did not run (or did not succeed): a domain answer, never an exception. The
 * GraphQL layer turns it into the admin API's NotFoundError / ValidationError.
 *
 * @since 1.5.0
 */
final readonly class PaymentRefusal
{
    public const NOT_FOUND = 'not_found';
    public const INVALID = 'invalid';

    private function __construct(
        public string $kind,
        public string $field,
        public string $code,
        public string $message,
    ) {
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'orderId', 'not_found', 'No payment was found for this order');
    }

    public static function invalid(string $field, string $code, string $message): self
    {
        return new self(self::INVALID, $field, $code, $message);
    }
}
