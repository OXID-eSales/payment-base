<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

/**
 * Provider-agnostic, character-level field validator.
 *
 * Each instance is bound to one plugin module id and its corresponding
 * rules file. Construct via `new ValidationBase($pluginModuleId, $loader)`.
 */
interface ValidationBaseInterface
{
    /**
     * Validates a single field value against the plugin's per-field rules
     * and the universal character blocklist.
     *
     * Returns FieldValidationResult::valid() when:
     *   - $value is null or empty string (empty check is left to OXID's RequiredFieldsValidator)
     *   - the field name has no entry in the loaded rules map (documented contract: unknown fields pass)
     *   - all characters pass the universal blocklist and the per-field allow/block rules
     *
     * @param mixed $value
     */
    public function validateField(string $name, mixed $value): FieldValidationResult;
}
