<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Payment\AdminChannelInterface;

/**
 * The admin API's door to the money path. The right is the mutation's own `#[Right]`, which the API refuses before
 * the call reaches the path, so there is nothing left to ask here; the step-up and the audit are the admin API's.
 *
 * @since 1.7.0
 */
final class GraphQlAdminChannel implements AdminChannelInterface
{
    public function __construct(
        private readonly AdminStepUpInterface $stepUp,
        private readonly AdminAuditInterface $audit,
    ) {
    }

    public function requireRight(string $right): void
    {
        // Enforced by #[Right] on adminPaymentCapture/Refund/Void before the path runs.
    }

    public function requireStepUp(): void
    {
        $this->stepUp->require();
    }

    public function audit(): AdminAuditInterface
    {
        return $this->audit;
    }
}
