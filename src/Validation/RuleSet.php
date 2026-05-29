<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

/**
 * Immutable value object representing the parsed allow + block token lists
 * for a single logical field.
 *
 * Token grammar (§4.3): space-separated. Consecutive spaces are collapsed.
 * Class tokens (uppercase): UNICODE_LETTERS, LETTERS, NUMBERS, SPACES.
 * Literal tokens: any other single character.
 */
final class RuleSet
{
    /**
     * @param list<string> $allowTokens
     * @param list<string> $blockTokens
     */
    private function __construct(
        private readonly array $allowTokens,
        private readonly array $blockTokens,
    ) {
    }

    /**
     * @param array{allow?: string, block?: string} $rules
     */
    public static function fromArray(array $rules): self
    {
        return new self(
            self::tokenise($rules['allow'] ?? ''),
            self::tokenise($rules['block'] ?? ''),
        );
    }

    /** @return list<string> */
    public function getAllowTokens(): array
    {
        return $this->allowTokens;
    }

    /** @return list<string> */
    public function getBlockTokens(): array
    {
        return $this->blockTokens;
    }

    public function hasAllowConstraint(): bool
    {
        return $this->allowTokens !== [];
    }

    /**
     * Split a space-separated token string, discarding empty segments
     * produced by consecutive spaces.
     *
     * @return list<string>
     */
    private static function tokenise(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $raw), static fn(string $t) => $t !== ''));
    }
}
