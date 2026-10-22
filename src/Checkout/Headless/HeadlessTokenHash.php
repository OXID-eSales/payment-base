<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * What the database keeps of a contract token: its SHA-256, never the token.
 * The token is a 128-bit random credential the client holds; a database read
 * (backup, replica, SQL injection elsewhere) must not be enough to return or
 * cancel somebody's contract. No salt or stretching on purpose: the input is
 * already high-entropy, so a fast hash is sufficient and keeps lookups cheap.
 *
 * @since 1.4.0
 */
final readonly class HeadlessTokenHash
{
    private function __construct(public string $value)
    {
    }

    public static function fromToken(string $token): self
    {
        return new self(hash('sha256', $token));
    }

    /**
     * The value read back from the contract metadata. Not validated: a
     * plaintext token stored by an older version simply never matches.
     */
    public static function fromStored(string $stored): self
    {
        return new self($stored);
    }

    public function matches(string $token): bool
    {
        return hash_equals($this->value, hash('sha256', $token));
    }
}
