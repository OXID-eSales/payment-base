<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Settings;

use RuntimeException;

/**
 * The module's configuration could not be read at all (DAO failure, wrong shop id, row gone).
 * Not the same as a setting that is merely unset: that one has a metadata default.
 */
final class ModuleConfigurationUnreadableException extends RuntimeException
{
}
