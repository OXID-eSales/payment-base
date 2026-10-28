<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * Hands an admin payment action to the provider module behind an order's contract: the same boundary that backs
 * the classic Payment tab.
 *
 * @since 1.5.0
 */
interface ProviderActionRunnerInterface
{
    /** The provider took the action (what it did is read back from the contract). */
    public const RAN = 'ran';

    /** No registered provider handles this contract. */
    public const UNAVAILABLE = 'unavailable';

    /** The provider threw; the reason is in the log, never in the answer. */
    public const FAILED = 'failed';

    /** The provider refused with a sentence meant for the admin ({@see \OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException}). */
    public const REFUSED = 'refused';

    /**
     * @param string               $action  `capture`, `refund` or `cancel`, as the classic tab names them
     * @param array<string, mixed> $request the classic tab's form fields (`capture_amount`, `refund_amount`, `refund_reason`, ...)
     *
     */
    public function run(string $provider, string $orderId, string $action, array $request): ProviderActionOutcome;
}
