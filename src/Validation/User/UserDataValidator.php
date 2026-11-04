<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\User;

use OxidEsales\PaymentBase\Validation\ValidationBaseFactoryInterface;

/**
 * Maps address fields to logical names and delegates the character-level check to the
 * module-bound ValidationBase of {@see ValidationBaseFactoryInterface}.
 */
final class UserDataValidator implements UserDataValidatorInterface
{
    /**
     * Logical fields validated for the billing and the delivery pass. A field without a rule in
     * the module's rules file is accepted, so `email` is only checked by modules that declare it.
     */
    private const LOGICAL_FIELDS = [
        'firstName', 'lastName', 'additionalInfo', 'street', 'houseNumber',
        'postalCode', 'city', 'company', 'vatId', 'phone', 'cellPhone',
        'personalPhone', 'fax', 'email',
    ];

    public function __construct(
        private readonly ValidationBaseFactoryInterface $factory,
    ) {
    }

    public function validateForUser(string $pluginModuleId, UserFieldReaderInterface $reader): array
    {
        $failures = $this->validateAddressPass($pluginModuleId, $reader, FieldValidationFailure::KIND_BILLING);
        if (!$reader->hasDeliveryAddress()) {
            return $failures;
        }

        return array_merge(
            $failures,
            $this->validateAddressPass($pluginModuleId, $reader, FieldValidationFailure::KIND_DELIVERY)
        );
    }

    public function validateFieldMap(
        string $pluginModuleId,
        array $fields,
        string $addressKind = FieldValidationFailure::KIND_BILLING,
    ): array {
        $failures = [];
        foreach ($fields as $logicalName => $value) {
            $failure = $this->validateSingleField($pluginModuleId, $logicalName, $value, $addressKind, null);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /** @return list<FieldValidationFailure> */
    private function validateAddressPass(
        string $pluginModuleId,
        UserFieldReaderInterface $reader,
        string $addressKind,
    ): array {
        $failures = [];
        foreach (self::LOGICAL_FIELDS as $logicalName) {
            $value = $addressKind === FieldValidationFailure::KIND_BILLING
                ? $reader->readBillingField($logicalName)
                : $reader->readDeliveryField($logicalName);
            $failure = $this->validateSingleField(
                $pluginModuleId,
                $logicalName,
                $value,
                $addressKind,
                OxidUserFieldReader::oxidColumn($logicalName)
            );
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function validateSingleField(
        string $pluginModuleId,
        string $logicalName,
        string $value,
        string $addressKind,
        ?string $oxidColumn,
    ): ?FieldValidationFailure {
        if ($value === '') {
            return null;
        }

        $result = $this->factory->create($pluginModuleId)->validateField($logicalName, $value);
        if ($result->valid) {
            return null;
        }

        return new FieldValidationFailure(
            field: $logicalName,
            addressKind: $addressKind,
            code: (string) $result->code,
            offendingChar: $result->offendingChar,
            oxidColumn: $oxidColumn,
        );
    }
}
