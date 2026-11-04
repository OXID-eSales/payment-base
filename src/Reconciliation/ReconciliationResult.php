<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Reconciliation;

/**
 * Outcome of reconciling one order. `action`: updated, skipped, dry_run, no_contract, error.
 */
final class ReconciliationResult
{
    public const ACTION_UPDATED = 'updated';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_DRY_RUN = 'dry_run';
    public const ACTION_NO_CONTRACT = 'no_contract';
    public const ACTION_ERROR = 'error';

    public function __construct(
        public readonly string $orderId,
        public readonly string $providerOrderId,
        public readonly bool $success,
        public readonly string $action,
        public readonly string $reason,
        public readonly bool $contractUpdated = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'provider_order_id' => $this->providerOrderId,
            'success' => $this->success,
            'action' => $this->action,
            'reason' => $this->reason,
            'contract_updated' => $this->contractUpdated,
        ];
    }
}
