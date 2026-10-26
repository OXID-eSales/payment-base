<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * One call of capture, refund or void as the client made it, with everything that follows from its kind:
 * the names it is audited and idempotency-keyed under, the provider action behind it, whether it moves money
 * (and therefore needs a step-up), and the checks that need nothing but the request itself.
 *
 * @since 1.5.0
 */
final readonly class AdminPaymentOperation
{
    public const CAPTURE = 'capture';
    public const REFUND = 'refund';
    public const VOID = 'void';

    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';
    private const MAX_REASON_LENGTH = 255;

    private function __construct(
        public string $kind,
        public string $orderId,
        public ?float $amount,
        public ?string $reason,
        public ?string $clientKey,
    ) {
    }

    public static function capture(string $orderId, ?float $amount, ?string $clientKey): self
    {
        return new self(self::CAPTURE, $orderId, $amount, null, $clientKey);
    }

    public static function refund(string $orderId, float $amount, ?string $reason, ?string $clientKey): self
    {
        $trimmed = $reason === null ? '' : trim($reason);

        return new self(self::REFUND, $orderId, $amount, $trimmed === '' ? null : $trimmed, $clientKey);
    }

    public static function void(string $orderId, ?string $clientKey): self
    {
        return new self(self::VOID, $orderId, null, null, $clientKey);
    }

    /** The name in the audit log and in the GraphQL schema. */
    public function name(): string
    {
        return 'adminPayment' . ucfirst($this->kind);
    }

    /** The name in `oe_payments_idempotency.OXOPERATION` (VARCHAR 32). */
    public function idempotencyName(): string
    {
        return 'admin_payment_' . $this->kind;
    }

    /** The action name of the classic Payment tab's provider boundary. */
    public function providerAction(): string
    {
        return $this->kind === self::VOID ? 'cancel' : $this->kind;
    }

    /** Capture and refund move money; releasing an authorisation does not. */
    public function movesMoney(): bool
    {
        return $this->kind !== self::VOID;
    }

    /**
     * @return list<string>
     */
    public function changedFields(): array
    {
        return array_values(array_filter([
            $this->amount === null ? null : 'amount',
            $this->reason === null ? null : 'reason',
        ]));
    }

    public function inputRefusal(): ?PaymentRefusal
    {
        if ($this->clientKey !== null && preg_match(self::KEY_PATTERN, $this->clientKey) !== 1) {
            return PaymentRefusal::invalid('idempotencyKey', 'invalid_key', 'The idempotency key must be 8 to 64 letters, digits, "-" or "_"');
        }
        if ($this->reason === null) {
            return null;
        }
        if (strlen($this->reason) > self::MAX_REASON_LENGTH) {
            return PaymentRefusal::invalid('reason', 'too_long', 'The reason is longer than 255 characters');
        }

        return preg_match(self::CONTROL_CHARACTERS, $this->reason) === 1
            ? PaymentRefusal::invalid('reason', 'invalid_characters', 'The reason must not contain control characters')
            : null;
    }
}
