<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

interface ValidationBaseFactoryInterface
{
    /** @param string $moduleId the module whose rules file the instance validates against */
    public function create(string $moduleId): ValidationBaseInterface;
}
