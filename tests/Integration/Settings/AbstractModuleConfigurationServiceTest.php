<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Settings;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setting\Setting;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\PaymentBase\Settings\AbstractModuleConfigurationService;
use OxidEsales\PaymentBase\Settings\ModuleConfigurationUnreadableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the fail-closed behaviour of the Mollie tests and the typed reads the Stripe and PayPal
 * services share. Unreadable (DAO throws) is an error; unset is a default.
 */
#[CoversClass(AbstractModuleConfigurationService::class)]
#[CoversClass(ModuleConfigurationUnreadableException::class)]
final class AbstractModuleConfigurationServiceTest extends TestCase
{
    public function testReadsTheSettingsOfTheModuleIdInTheCurrentShop(): void
    {
        $dao = $this->createMock(ModuleConfigurationDaoInterface::class);
        $dao->expects(self::once())->method('get')->with('oe_test_module', 7)->willReturn($this->configurationWith(['sMode' => 'live']));

        $service = $this->service($dao, shopId: 7);

        self::assertSame('live', $service->get('sMode'));
    }

    public function testAnUnsetSettingIsEmptyAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('setting unreadable'), self::anything());
        $logger->expects(self::never())->method('error');

        $service = $this->service($this->daoReturning($this->configurationWith([])), logger: $logger);

        self::assertSame('', $service->get('sMissing'));
        $service->assertReadableForTest();
    }

    public function testReadStringReturnsStringsAndEmptyForOtherTypes(): void
    {
        $service = $this->service($this->daoReturning($this->configurationWith(['sKey' => 'abc', 'aList' => ['x'], 'bFlag' => true])));

        self::assertSame('abc', $service->readStringForTest('sKey'));
        self::assertSame('', $service->readStringForTest('aList'));
        self::assertSame('', $service->readStringForTest('bFlag'));
    }

    public function testAnUnreadableConfigurationRefusesAndIsLoggedAtErrorLevel(): void
    {
        $dao = $this->createMock(ModuleConfigurationDaoInterface::class);
        $dao->method('get')->willThrowException(new RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeast(2))->method('error')->with(
            self::stringContains('oe_test_module'),
            self::callback(static fn (array $context): bool => $context !== []),
        );

        $service = $this->service($dao, logger: $logger);

        $this->expectException(ModuleConfigurationUnreadableException::class);
        $service->assertReadableForTest();
    }

    public function testAnUnreadableConfigurationStillAnswersEmptySettings(): void
    {
        $dao = $this->createMock(ModuleConfigurationDaoInterface::class);
        $dao->method('get')->willThrowException(new RuntimeException('db down'));

        self::assertSame('', $this->service($dao)->get('sAnything'));
    }

    public function testAReadableConfigurationLogsNoError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $this->service($this->daoReturning($this->configurationWith(['sMode' => 'live'])), logger: $logger)->assertReadableForTest();
    }

    /** @param array<string, mixed> $settings */
    private function configurationWith(array $settings): ModuleConfiguration
    {
        $configuration = new ModuleConfiguration();
        foreach ($settings as $name => $value) {
            $configuration->addModuleSetting((new Setting())->setName($name)->setValue($value));
        }

        return $configuration;
    }

    private function daoReturning(ModuleConfiguration $configuration): ModuleConfigurationDaoInterface
    {
        $dao = $this->createStub(ModuleConfigurationDaoInterface::class);
        $dao->method('get')->willReturn($configuration);

        return $dao;
    }

    private function service(
        ModuleConfigurationDaoInterface $dao,
        int $shopId = 1,
        ?LoggerInterface $logger = null,
    ): AbstractModuleConfigurationServiceProbe {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getCurrentShopId')->willReturn($shopId);

        return new AbstractModuleConfigurationServiceProbe($context, $dao, $logger ?? $this->createStub(LoggerInterface::class));
    }
}
