<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;

/**
 * The door an admin comes through to the one money path: the admin API or the classic admin. The door knows who is
 * asking, so the right and the step-up are checked against that admin, and where the audit trail is kept. What the
 * operation then does is the same for every door.
 *
 * @since 1.7.0
 */
interface AdminChannelInterface
{
    /**
     * @throws AdminAccessDeniedException the admin does not hold the right
     */
    public function requireRight(string $right): void;

    /**
     * @throws AdminAccessDeniedException the admin has not proven themselves recently enough for a money movement
     */
    public function requireStepUp(): void;

    public function audit(): AdminAuditInterface;
}
