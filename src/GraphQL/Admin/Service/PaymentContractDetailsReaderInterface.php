<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * @since 1.5.0
 */
interface PaymentContractDetailsReaderInterface
{
    public function findByOrderId(string $orderId): ?PaymentContractDetails;
}
