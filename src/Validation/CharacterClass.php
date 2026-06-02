<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

/**
 * Static helpers for character-level classification.
 *
 * Implements the universal blocklist (§4.2) and the per-field named class
 * tokens (§4.3) of the ValidationBase grammar.
 *
 * All methods are pure functions with no side effects — safe to use
 * without instantiation.
 */
class CharacterClass
{
    /**
     * PCRE pattern covering all universally-rejected code points:
     *   - C0 controls U+0000–U+001F (includes null, tab, CR, LF)
     *   - DEL U+007F
     *   - C1 controls U+0080–U+009F
     *   - Zero-width / invisible specials U+200B, U+200C, U+200D, U+FEFF,
     *     U+00AD (soft hyphen), U+2060 (word joiner)
     */
    private const UNIVERSAL_REJECT_PATTERN =
        '/(?P<bad>[\x00-\x1F\x7F\x80-\x9F\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}\x{2060}])/u';

    /**
     * Returns the first offending character from the universal blocklist,
     * or null if the value is clean.
     */
    public static function hasUniversalReject(string $value): ?string
    {
        if (preg_match(self::UNIVERSAL_REJECT_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        return $matches['bad'];
    }

    /**
     * Returns true if $token is a named class token (all-uppercase, e.g. UNICODE_LETTERS).
     *
     * Class tokens are distinguished from literal characters by being all-uppercase
     * ASCII identifiers. Any token with a lowercase letter or non-alpha character is
     * treated as a literal single character.
     */
    public static function isClassToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Z_]+$/', $token);
    }

    /**
     * Returns true if $char matches the given class token.
     *
     * Supported tokens:
     *   UNICODE_LETTERS — any Unicode letter (\p{L})
     *   LETTERS         — ASCII a-z / A-Z only
     *   NUMBERS         — any Unicode numeric (\p{N})
     *   SPACES          — the regular U+0020 space only (NOT \s)
     */
    public static function matchesClass(string $char, string $classToken): bool
    {
        return match ($classToken) {
            'UNICODE_LETTERS' => (bool) preg_match('/^\p{L}$/u', $char),
            'LETTERS'         => (bool) preg_match('/^[A-Za-z]$/', $char),
            'NUMBERS'         => (bool) preg_match('/^\p{N}$/u', $char),
            'SPACES'          => $char === ' ',
            default           => false,
        };
    }
}
