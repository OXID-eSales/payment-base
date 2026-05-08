<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\PaymentCustomer;

/**
 * Repository interface for payment customer records.
 *
 * Sprint 45: Stripe Customer lifecycle.
 *
 * @since 1.0.0
 */
interface PaymentCustomerRepositoryInterface
{
    public function save(PaymentCustomer $customer): void;

    public function findByUserId(string $userId): ?PaymentCustomer;

    public function findByPaymentCustomerId(string $paymentCustomerId): ?PaymentCustomer;
}
