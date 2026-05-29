<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

/**
 * Immutable value object returned by ValidationBase::validateField().
 *
 * Use the static factory methods to construct; do not instantiate directly.
 */
final class FieldValidationResult
{
    public const CODE_DISALLOWED_CHARACTER = 'disallowed_character';
    public const CODE_BLOCKED_CHARACTER = 'blocked_character';
    public const CODE_CONTROL_CHARACTER = 'control_character';

    private function __construct(
        public readonly bool $valid,
        public readonly ?string $code,
        public readonly ?string $offendingChar,
    ) {
    }

    public static function valid(): self
    {
        return new self(true, null, null);
    }

    public static function disallowed(string $char): self
    {
        return new self(false, self::CODE_DISALLOWED_CHARACTER, $char);
    }

    public static function blocked(string $char): self
    {
        return new self(false, self::CODE_BLOCKED_CHARACTER, $char);
    }

    public static function controlCharacter(string $char): self
    {
        return new self(false, self::CODE_CONTROL_CHARACTER, $char);
    }
}
