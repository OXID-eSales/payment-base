<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

/**
 * Loads per-field validation rule sets for a given plugin module.
 *
 * Implementations resolve the per-plugin rules file and return a map
 * of logical field name → RuleSet.
 */
interface ValidationRuleLoaderInterface
{
    /**
     * @return array<string, RuleSet>  Map of logical field name → RuleSet
     * @throws \InvalidArgumentException if no rules file is found for $pluginModuleId
     */
    public function loadFor(string $pluginModuleId): array;
}
