<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\ClassicAdminChannel;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\AdminRightCheckInterface;
use OxidEsales\PaymentBase\Payment\ClassicAdminSessionInterface;
use OxidEsales\PaymentBase\Payment\SecondFactorPolicyInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\RecordingAudit;
use PHPUnit\Framework\TestCase;

final class ClassicAdminChannelTest extends TestCase
{
    public function testRight_IsAskedForTheAdminOfTheSessionInTheirShop(): void
    {
        $rights = $this->createMock(AdminRightCheckInterface::class);
        $rights->expects(self::once())->method('allows')->with('admin-7', 3, 'ADMIN_PAYMENT_REFUND')->willReturn(true);

        $this->channel(rights: $rights)->requireRight('ADMIN_PAYMENT_REFUND');
    }

    public function testRight_Missing_IsDenied(): void
    {
        $rights = $this->createStub(AdminRightCheckInterface::class);
        $rights->method('allows')->willReturn(false);

        try {
            $this->channel(rights: $rights)->requireRight('ADMIN_PAYMENT_REFUND');
            self::fail('the admin without the right must be denied');
        } catch (AdminAccessDeniedException $denied) {
            self::assertSame('right_missing', $denied->denialCode());
        }
    }

    public function testNoAdminInTheSession_IsDeniedWithoutAskingForRights(): void
    {
        $rights = $this->createMock(AdminRightCheckInterface::class);
        $rights->expects(self::never())->method('allows');

        $this->expectException(AdminAccessDeniedException::class);

        $this->channel(rights: $rights, userId: '')->requireRight('ADMIN_PAYMENT_REFUND');
    }

    public function testStepUp_WhenTheShopRequiresASecondFactor_IsDenied_BecauseTheClassicAdminCannotAskForIt(): void
    {
        try {
            $this->channel(secondFactorRequired: true)->requireStepUp();
            self::fail('the classic admin has no second factor to ask');
        } catch (AdminAccessDeniedException $denied) {
            self::assertSame('step_up_required', $denied->denialCode());
        }
    }

    public function testStepUp_WhenNoSecondFactorIsRequired_Passes(): void
    {
        $this->channel(secondFactorRequired: false)->requireStepUp();

        $this->addToAssertionCount(1);
    }

    public function testAudit_IsTheOneItWasGiven(): void
    {
        $audit = new RecordingAudit();

        self::assertSame($audit, $this->channel(audit: $audit)->audit());
    }

    private function channel(
        ?AdminRightCheckInterface $rights = null,
        string $userId = 'admin-7',
        bool $secondFactorRequired = false,
        ?RecordingAudit $audit = null,
    ): ClassicAdminChannel {
        $session = $this->createStub(ClassicAdminSessionInterface::class);
        $session->method('userId')->willReturn($userId);
        $session->method('shopId')->willReturn(3);
        $policy = $this->createStub(SecondFactorPolicyInterface::class);
        $policy->method('isRequired')->willReturn($secondFactorRequired);

        return new ClassicAdminChannel($session, $rights ?? $this->createStub(AdminRightCheckInterface::class), $policy, $audit ?? new RecordingAudit());
    }
}
