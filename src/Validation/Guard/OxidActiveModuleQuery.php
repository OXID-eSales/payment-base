<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Setup\Bridge\ModuleActivationBridgeInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\BasicContextInterface;

/**
 * OXID-backed implementation of ActiveModuleQueryInterface.
 *
 * Delegates to ModuleActivationBridgeInterface which reads the shop's
 * module-state YAML files without touching the DB.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
class OxidActiveModuleQuery implements ActiveModuleQueryInterface
{
    public function __construct(
        private readonly ModuleActivationBridgeInterface $bridge,
        private readonly BasicContextInterface $context,
    ) {
    }

    public function isActive(string $pluginModuleId): bool
    {
        return $this->bridge->isActive($pluginModuleId, $this->context->getCurrentShopId());
    }
}
