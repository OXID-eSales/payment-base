<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * @since 1.8.0
 */
interface AtRiskOwnerMailerInterface
{
    /**
     * @return bool false when the shop's mailer refused the mail
     */
    public function notify(PaymentContractInterface $contract, AtRiskReason $reason, string $reference): bool;
}
