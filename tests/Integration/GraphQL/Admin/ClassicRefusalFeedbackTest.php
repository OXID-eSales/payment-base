<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Admin\AdminRefusalFeedbackInterface;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\PaymentAdminActionDispatcher;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakePanelProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * PAY-1 S5 regression: a provider's typed refusal does not fail the classic Payment tab; the shop's own error
 * display shows it (unless the provider recorded its own classic feedback). Real container, real session.
 */
#[Group('integration')]
final class ClassicRefusalFeedbackTest extends IntegrationTestCase
{
    public function testTheClassicDispatcher_ShowsTheProvidersSentenceInTheShopsErrorDisplay_AndDoesNotThrow(): void
    {
        Registry::getSession()->deleteVariable('Errors');

        $this->dispatcherThrowing(new AdminActionRefusedException('too_small', 'The amount is below the provider minimum.'))
            ->dispatch('refund', [], new PaymentPanelContext('o1', 'oxidinvoice', ContractFactory::authorized('o1', 'paypal')));

        self::assertStringContainsString('below the provider minimum', serialize(Registry::getSession()->getVariable('Errors')));
    }

    public function testAProviderThatRecordedItsOwnFeedback_IsNotShownTwice(): void
    {
        Registry::getSession()->deleteVariable('Errors');

        $this->dispatcherThrowing(new AdminActionRefusedException('too_small', 'Already shown elsewhere.', feedbackRecorded: true))
            ->dispatch('refund', [], new PaymentPanelContext('o1', 'oxidinvoice', ContractFactory::authorized('o1', 'paypal')));

        self::assertStringNotContainsString('Already shown', serialize(Registry::getSession()->getVariable('Errors')));
    }

    private function dispatcherThrowing(AdminActionRefusedException $refusal): PaymentAdminActionDispatcher
    {
        $feedback = ContainerFactory::getInstance()->getContainer()->get(AdminRefusalFeedbackInterface::class);
        self::assertInstanceOf(AdminRefusalFeedbackInterface::class, $feedback);
        $provider = new FakePanelProvider('paypal', static function () use ($refusal): void {
            throw $refusal;
        });

        return new PaymentAdminActionDispatcher(new PaymentPanelRegistry([$provider]), $feedback);
    }
}
