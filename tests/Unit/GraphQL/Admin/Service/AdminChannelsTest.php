<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\GraphQL\Admin\Authorization\DataType\AdminIdentity;
use OxidEsales\GraphQL\Admin\Authorization\Service\AdminRightResolverInterface;
use OxidEsales\GraphQL\AdminApi\Mfa\Service\MfaSettings;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\GraphQlAdminChannel;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OxidAdminRightCheck;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OxidAdminSecondFactorPolicy;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\CountingStepUp;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\RecordingAudit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The glue between the one money path and the admin API's own services.
 */
final class AdminChannelsTest extends TestCase
{
    public function testGraphQlChannel_StepUpIsTheAdminApisStepUp_AndItsRefusalPropagates(): void
    {
        $stepUp = new CountingStepUp(refuse: true);

        try {
            (new GraphQlAdminChannel($stepUp, new RecordingAudit()))->requireStepUp();
            self::fail('the admin API refuses an admin without a fresh step-up');
        } catch (RuntimeException $refusal) {
            self::assertSame('step_up_required', $refusal->getMessage());
        }
        self::assertSame(1, $stepUp->required);
    }

    public function testGraphQlChannel_TheRightIsTheMutationsOwn_AndTheAuditIsTheOneItWasGiven(): void
    {
        $audit = new RecordingAudit();
        $channel = new GraphQlAdminChannel(new CountingStepUp(), $audit);

        $channel->requireRight('ADMIN_PAYMENT_CAPTURE');

        self::assertSame($audit, $channel->audit());
    }

    public function testRightCheck_AsksTheAdminApisResolverForTheIdentity(): void
    {
        $resolver = $this->createMock(AdminRightResolverInterface::class);
        $resolver->expects(self::once())->method('allows')
            ->with(self::callback(static fn (AdminIdentity $admin): bool => $admin->userId === 'admin-1' && $admin->shopId === 2), 'ADMIN_PAYMENT_REFUND')
            ->willReturn(false);

        self::assertFalse((new OxidAdminRightCheck($resolver))->allows('admin-1', 2, 'ADMIN_PAYMENT_REFUND'));
    }

    public function testRightCheck_WithoutTheAdminApi_HasNoCatalogue_AndTheOxidAdminLoginIsTheGate(): void
    {
        self::assertTrue((new OxidAdminRightCheck(null))->allows('admin-1', 1, 'ADMIN_PAYMENT_REFUND'));
    }

    public function testSecondFactor_FollowsTheAdminApisMfaSetting(): void
    {
        $settings = $this->createStub(MfaSettings::class);
        $settings->method('isRequired')->willReturn(true);

        self::assertTrue((new OxidAdminSecondFactorPolicy($settings))->isRequired());
    }

    public function testSecondFactor_WithoutTheAdminApi_IsNotRequired_ThereIsNothingToAskIt(): void
    {
        self::assertFalse((new OxidAdminSecondFactorPolicy(null))->isRequired());
    }
}
