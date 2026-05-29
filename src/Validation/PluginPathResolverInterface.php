<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

/**
 * Narrow interface for resolving the filesystem root path of a plugin module.
 *
 * Exists so unit tests can stub path resolution without booting the OXID shop.
 * The production implementation wraps OXID's ModulePathResolverInterface.
 */
interface PluginPathResolverInterface
{
    /**
     * Returns the absolute filesystem path to the root directory of the
     * given OXID module (i.e. the directory that contains src/, tests/, etc.).
     */
    public function resolvePath(string $pluginModuleId): string;
}
