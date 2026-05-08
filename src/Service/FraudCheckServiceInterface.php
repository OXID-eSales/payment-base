<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Adapter\Response\FraudCheckResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Interface for fraud check services.
 *
 * Sprint 2: Contract-aware fraud checking interface.
 * Interface lives in payment-base, implementation in provider modules.
 *
 * Sprint 31: Returns FraudCheckResponse instead of FraudCheckResult.
 *
 * For Stripe: Uses Stripe Radar score with configurable threshold (default 0.7).
 * - Score < threshold: Pass
 * - Score >= threshold: Fail (contract will be cancelled)
 *
 * @since 1.0.0
 */
interface FraudCheckServiceInterface
{
    /**
     * Run fraud check for a contract.
     *
     * @param PaymentContractInterface $contract Contract to check
     * @return FraudCheckResponse Pass/Fail response with score and reason
     */
    public function check(PaymentContractInterface $contract): FraudCheckResponse;
}
