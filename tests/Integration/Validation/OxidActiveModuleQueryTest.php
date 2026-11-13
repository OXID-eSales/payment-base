<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Validation;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\BasicContextInterface;
use OxidEsales\PaymentBase\Validation\Guard\OxidActiveModuleQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 29 (VRL, appendix D of Mythos O-08): an unknown module id is "not active", not an exception (HTTP 500).
 * In the shop-context suite: the CE module DAO is not part of the standalone unit suite's autoloader.
 */
#[Group('integration')]
#[CoversClass(OxidActiveModuleQuery::class)]
final class OxidActiveModuleQueryTest extends TestCase
{
    public function testAnUnknownModuleId_IsNotActive_AndIsNotLookedUp(): void
    {
        $dao = $this->createMock(ModuleConfigurationDaoInterface::class);
        $dao->method('exists')->with('invented-id', 1)->willReturn(false);
        $dao->expects($this->never())->method('get');

        $this->assertFalse($this->query($dao)->isActive('invented-id'));
    }

    public function testAnActivatedModule_IsActive(): void
    {
        $this->assertTrue($this->query($this->daoWith(activated: true))->isActive('some_module'));
    }

    public function testAnInstalledButDeactivatedModule_IsNotActive(): void
    {
        $this->assertFalse($this->query($this->daoWith(activated: false))->isActive('some_module'));
    }

    private function daoWith(bool $activated): ModuleConfigurationDaoInterface
    {
        $dao = $this->createStub(ModuleConfigurationDaoInterface::class);
        $dao->method('exists')->willReturn(true);
        $dao->method('get')->willReturn((new ModuleConfiguration())->setId('some_module')->setActivated($activated));

        return $dao;
    }

    private function query(ModuleConfigurationDaoInterface $dao): OxidActiveModuleQuery
    {
        $context = $this->createStub(BasicContextInterface::class);
        $context->method('getCurrentShopId')->willReturn(1);

        return new OxidActiveModuleQuery($dao, $context);
    }
}
