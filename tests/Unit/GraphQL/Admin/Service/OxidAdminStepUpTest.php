<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use LogicException;
use OxidEsales\GraphQL\Admin\Mfa\Service\StepUp;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OxidAdminStepUp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OxidAdminStepUpTest extends TestCase
{
    public function testRequire_AsksTheAdminModulesStepUpForTheFiveMinuteWindow(): void
    {
        $stepUp = $this->createMock(StepUp::class);
        $stepUp->expects(self::once())->method('require')->with(StepUp::FIVE_MINUTES);

        (new OxidAdminStepUp($stepUp))->require();
    }

    public function testRequire_TheAdminModulesRefusalPropagatesUnchanged(): void
    {
        $stepUp = $this->createStub(StepUp::class);
        $stepUp->method('require')->willThrowException(new RuntimeException('step_up_required'));

        $this->expectExceptionMessage('step_up_required');

        (new OxidAdminStepUp($stepUp))->require();
    }

    public function testRequire_WithoutTheAdminModule_FailsClosed(): void
    {
        $this->expectException(LogicException::class);

        (new OxidAdminStepUp(null))->require();
    }
}
