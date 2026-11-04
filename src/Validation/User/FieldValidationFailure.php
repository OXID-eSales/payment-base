<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\User;

/**
 * One field-level violation of the character-level user data rules.
 *
 * `oxidColumn` is null when the value did not come from an OXID model (flat field maps).
 */
final class FieldValidationFailure
{
    public const KIND_BILLING = 'billing';
    public const KIND_DELIVERY = 'delivery';
    public const KIND_ADMIN = 'admin';

    /**
     * @param string $addressKind {@see self::KIND_BILLING}, {@see self::KIND_DELIVERY} or
     *                            {@see self::KIND_ADMIN} for admin free text
     */
    public function __construct(
        public readonly string $field,
        public readonly string $addressKind,
        public readonly string $code,
        public readonly ?string $offendingChar,
        public readonly ?string $oxidColumn,
    ) {
    }
}
