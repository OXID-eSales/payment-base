<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\BasicContextInterface;

/**
 * OXID-backed implementation of ActiveModuleQueryInterface, read from the shop's module configuration (YAML, no DB).
 *
 * Sprint 29 (VRL, appendix D of Mythos O-08): an id the shop has no configuration for is "not active". The
 * activation bridge used before threw ModuleConfigurationNotFoundException for it, which the endpoint answered
 * with HTTP 500 instead of the allowlist's 422.
 */
class OxidActiveModuleQuery implements ActiveModuleQueryInterface
{
    public function __construct(
        private readonly ModuleConfigurationDaoInterface $moduleConfigurationDao,
        private readonly BasicContextInterface $context,
    ) {
    }

    public function isActive(string $pluginModuleId): bool
    {
        $shopId = $this->context->getCurrentShopId();
        if (!$this->moduleConfigurationDao->exists($pluginModuleId, $shopId)) {
            return false;
        }

        return $this->moduleConfigurationDao->get($pluginModuleId, $shopId)->isActivated();
    }
}
