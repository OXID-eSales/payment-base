<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\AdminChannelInterface;
use OxidEsales\PaymentBase\Payment\AdminRightCheckInterface;
use OxidEsales\PaymentBase\Payment\ClassicAdminSessionInterface;
use OxidEsales\PaymentBase\Payment\SecondFactorPolicyInterface;

/**
 * The classic admin's door to the money path. The admin is the one of the OXID admin session; the rights are the
 * admin API's catalogue, so one admin holds one set of payment rights whichever door they use. The classic admin cannot
 * ask for a second factor: where the shop requires one for money movements, they are done through the admin API.
 *
 * @since 1.7.0
 */
final class ClassicAdminChannel implements AdminChannelInterface
{
    public function __construct(
        private readonly ClassicAdminSessionInterface $session,
        private readonly AdminRightCheckInterface $rights,
        private readonly SecondFactorPolicyInterface $secondFactor,
        private readonly AdminAuditInterface $audit,
    ) {
    }

    public function requireRight(string $right): void
    {
        $userId = $this->session->userId();
        if ($userId === '' || !$this->rights->allows($userId, $this->session->shopId(), $right)) {
            throw new AdminAccessDeniedException('right_missing', 'You do not hold the right for this payment action');
        }
    }

    public function requireStepUp(): void
    {
        if ($this->secondFactor->isRequired()) {
            throw new AdminAccessDeniedException(
                'step_up_required',
                'This shop asks for a second factor on payment actions, which the classic admin cannot ask for: use the admin API'
            );
        }
    }

    public function audit(): AdminAuditInterface
    {
        return $this->audit;
    }
}
