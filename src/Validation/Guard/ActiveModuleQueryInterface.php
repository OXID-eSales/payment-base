<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Narrow interface for checking whether an OXID module is active.
 *
 * The production implementation wraps ModuleActivationBridgeInterface
 * (OXID core). Unit tests stub this without booting the shop.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
interface ActiveModuleQueryInterface
{
    public function isActive(string $pluginModuleId): bool;
}
