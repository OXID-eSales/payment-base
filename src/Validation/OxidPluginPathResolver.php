<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\BasicContextInterface;

/**
 * OXID-backed implementation of PluginPathResolverInterface.
 *
 * Resolves the filesystem root path of a plugin module by combining
 * OXID's shop root path with the module's registered source path from
 * the module configuration DAO.
 *
 * Registered in services.yaml; never instantiated in tests
 * (tests use a stub of PluginPathResolverInterface directly).
 */
class OxidPluginPathResolver implements PluginPathResolverInterface
{
    public function __construct(
        private readonly ModuleConfigurationDaoInterface $moduleConfigurationDao,
        private readonly BasicContextInterface $context,
    ) {
    }

    public function resolvePath(string $pluginModuleId): string
    {
        $moduleConfiguration = $this->moduleConfigurationDao->get(
            $pluginModuleId,
            $this->context->getCurrentShopId()
        );

        return $this->context->getShopRootPath() . '/' . $moduleConfiguration->getModuleSource();
    }
}
