<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment\Support;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\AdminChannelInterface;

/**
 * An admin door as a test double: records the rights and step-ups the runner demanded and can deny either.
 */
final class RecordingChannel implements AdminChannelInterface
{
    /** @var list<string> */
    public array $rights = [];
    public int $stepUps = 0;

    public function __construct(
        public readonly AdminAuditInterface $trail,
        private readonly ?string $deniedRight = null,
        private readonly bool $refuseStepUp = false,
    ) {
    }

    public function requireRight(string $right): void
    {
        $this->rights[] = $right;
        if ($right === $this->deniedRight) {
            throw new AdminAccessDeniedException('right_missing', 'No right');
        }
    }

    public function requireStepUp(): void
    {
        ++$this->stepUps;
        if ($this->refuseStepUp) {
            throw new AdminAccessDeniedException('step_up_required', 'Step-up');
        }
    }

    public function audit(): AdminAuditInterface
    {
        return $this->trail;
    }
}
