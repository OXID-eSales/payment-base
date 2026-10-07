<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * "The open checkout attempt for this user's basket" - the headless
 * previous-attempt lookup when no session registry can answer.
 *
 * Sprint 15 / S3 (GRAPH-QL), split out in S8: this started as a method on
 * ContractRepositoryInterface, which the provider modules' test doubles
 * implement, so the addition broke their suites. A separate interface that
 * the Doctrine repository also implements keeps payment-base additive.
 *
 * @since 3.0.0
 */
interface OpenAttemptFinderInterface
{
    /**
     * The newest contract of this user that is still open (NOT_FINISHED or
     * PENDING - no money taken) and carries `basket_id` = $basketId in its
     * metadata. Null when there is none.
     */
    public function findOpenByUserAndBasketId(string $userId, string $basketId): ?PaymentContractInterface;
}
