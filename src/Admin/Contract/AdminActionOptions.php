<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Contract;

/**
 * What a provider accepts as the free text of a capture, refund or void, so the admin UI can offer the right
 * input: a list of allowed refund / void reasons (empty = free text), whether a refund takes a description, and the
 * longest text the provider takes (never more than the API's own ceiling of 255 characters).
 *
 * @since 1.7.0
 */
final readonly class AdminActionOptions
{
    public const MAX_REASON_LENGTH = 255;

    /** @var list<string> */
    public array $refundReasons;

    /** @var list<string> */
    public array $voidReasons;

    public int $reasonMaxLength;

    /**
     * @param list<string> $refundReasons the only reasons a refund accepts; empty: free text
     * @param list<string> $voidReasons   the only reasons a void accepts; empty: free text
     */
    public function __construct(
        array $refundReasons,
        array $voidReasons,
        public bool $refundDescription,
        int $reasonMaxLength,
    ) {
        $this->refundReasons = self::distinctNonEmpty($refundReasons);
        $this->voidReasons = self::distinctNonEmpty($voidReasons);
        $this->reasonMaxLength = max(1, min(self::MAX_REASON_LENGTH, $reasonMaxLength));
    }

    /** What a provider that declares nothing gets: today's behaviour. */
    public static function freeText(): self
    {
        return new self([], [], false, self::MAX_REASON_LENGTH);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function distinctNonEmpty(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
    }
}
