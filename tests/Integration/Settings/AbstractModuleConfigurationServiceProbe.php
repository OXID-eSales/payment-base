<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Settings;

use OxidEsales\PaymentBase\Settings\AbstractModuleConfigurationService;

/** Concrete subclass exposing the protected helpers of the abstract service. */
final class AbstractModuleConfigurationServiceProbe extends AbstractModuleConfigurationService
{
    protected function moduleId(): string
    {
        return 'oe_test_module';
    }

    public function assertReadableForTest(): void
    {
        $this->assertReadable();
    }

    public function readStringForTest(string $name): string
    {
        return $this->readString($name);
    }
}
