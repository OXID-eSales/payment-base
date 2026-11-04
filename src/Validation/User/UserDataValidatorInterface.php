<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\User;

/**
 * Runs shopper address data and admin free text through payment-base's character-level
 * validation engine, bound to the rules file of the calling provider module.
 *
 * Stateless: the provider's module id is a call argument, so one shared service serves every
 * provider and no provider's id can leak into another's validation.
 */
interface UserDataValidatorInterface
{
    /**
     * Billing address first; the delivery address too when one is selected.
     *
     * @param string $pluginModuleId module whose rules file applies
     *
     * @return list<FieldValidationFailure>
     */
    public function validateForUser(string $pluginModuleId, UserFieldReaderInterface $reader): array;

    /**
     * @param string $pluginModuleId module whose rules file applies
     * @param array<string, string> $fields logical field name => value
     * @param string $addressKind reported on every failure ({@see FieldValidationFailure::KIND_*})
     *
     * @return list<FieldValidationFailure>
     */
    public function validateFieldMap(
        string $pluginModuleId,
        array $fields,
        string $addressKind = FieldValidationFailure::KIND_BILLING,
    ): array;
}
