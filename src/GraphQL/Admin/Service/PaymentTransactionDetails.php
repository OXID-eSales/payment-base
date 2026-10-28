<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use DateTimeImmutable;

/**
 * One transaction of a contract as the admin API shows it: ids and amounts only, never a provider payload.
 *
 * @since 1.5.0
 */
final readonly class PaymentTransactionDetails
{
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public float $amount,
        public string $currency,
        public DateTimeImmutable $createdAt,
        public ?string $providerReference,
    ) {
    }
}
