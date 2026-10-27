<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use RuntimeException;

final class CountingStepUp implements AdminStepUpInterface
{
    public int $required = 0;

    public function __construct(private readonly bool $refuse = false)
    {
    }

    public function require(): void
    {
        ++$this->required;
        if ($this->refuse) {
            throw new RuntimeException('step_up_required');
        }
    }
}
