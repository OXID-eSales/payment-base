<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use DateTimeImmutable;

/**
 * OPC-175 — the provider attempts a contract replaced, oldest first.
 *
 * Kept in the contract's metadata (`replaced_attempts`), so it travels with
 * toArray()/fromArray() and the repository without a schema change. A refund
 * of money that arrives on a replaced attempt finds the attempt here.
 *
 * @phpstan-type ReplacedAttempt array{provider: string, providerOrderId: string, paymentMethodId: string, closure: string, replacedAt: string}
 *
 * @since OPC-175
 */
final class ReplacedAttempts
{
    public const METADATA_KEY = 'replaced_attempts';

    /**
     * @return list<ReplacedAttempt>
     */
    public static function of(PaymentContractInterface $contract): array
    {
        return self::fromMetadata($contract->getMetadata(self::METADATA_KEY));
    }

    /**
     * @return list<ReplacedAttempt>
     */
    public static function withAttempt(
        mixed $stored,
        ?string $provider,
        ?string $providerOrderId,
        mixed $paymentMethodId,
        AttemptClosure $closure
    ): array {
        $history = self::fromMetadata($stored);
        $history[] = [
            'provider' => (string) $provider,
            'providerOrderId' => (string) $providerOrderId,
            'paymentMethodId' => is_string($paymentMethodId) ? $paymentMethodId : '',
            'closure' => $closure->value,
            'replacedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        return $history;
    }

    /**
     * @return list<ReplacedAttempt>
     */
    private static function fromMetadata(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        /** @var list<ReplacedAttempt> $stored */
        return $stored;
    }
}
