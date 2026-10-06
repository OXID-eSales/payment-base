<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Open a contract for a persisted user basket without starting a PSP
 * session: the first half of a provider's "Place Order" - contract (DRAFT),
 * ContractDraftCompletedEvent, early order (NOT_FINISHED), PENDING - for
 * channels that pay later with a delegated token (ACP `create_checkout`).
 *
 * Sprint 15 / S7 (GRAPH-QL).
 *
 * @since 3.0.0
 */
interface ContractOpeningServiceInterface
{
    /**
     * @param string $channel who opened it: `acp`, `ucp`, ... (travels as `channel` in the event context and contract metadata)
     * @throws HeadlessCheckoutException
     */
    public function open(string $userId, string $basketId, string $paymentId, string $channel): PaymentContractInterface;
}
