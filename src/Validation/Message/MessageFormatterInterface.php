<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Message;

/**
 * SPI: PSP modules implement this to supply human-readable translated messages
 * for field-validation failures emitted by the central validation endpoint.
 *
 * `ValidationApiController` collects tagged implementations
 * (tag: `oe.payment_base.validation_message_formatter`) and, for each error
 * in the JSON response, calls `format()` on the formatter whose
 * `getPluginModuleId()` matches the request's `pluginModuleId`.
 *
 * If no formatter matches, `message` is `null` (backwards-compatible behaviour).
 *
 * Sprint 119 Phase E (STRP-129).
 */
interface MessageFormatterInterface
{
    /**
     * The plugin module ID this formatter handles.
     *
     * Must match the `pluginModuleId` sent in the validation POST request.
     */
    public function getPluginModuleId(): string;

    /**
     * Return a translated, user-friendly message for a single field failure.
     *
     * @param string      $field         Logical field name (e.g. 'firstName').
     * @param string      $code          Violation code from FieldValidationResult::CODE_*.
     * @param string|null $offendingChar The offending character, or null when unavailable.
     */
    public function format(string $field, string $code, ?string $offendingChar): string;
}
