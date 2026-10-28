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
        public ?string $description = null,
    ) {
    }

    public static function capture(string $orderId, ?float $amount, ?string $clientKey, ?string $reason = null): self
    {
        return new self(self::CAPTURE, $orderId, $amount, self::blankToNull($reason), $clientKey);
    }

    public static function refund(string $orderId, float $amount, ?string $reason, ?string $clientKey, ?string $description = null): self
    {
        return new self(self::REFUND, $orderId, $amount, self::blankToNull($reason), $clientKey, self::blankToNull($description));
    }

    public static function void(string $orderId, ?string $clientKey, ?string $reason = null): self
    {
        return new self(self::VOID, $orderId, null, self::blankToNull($reason), $clientKey);
    }

    private static function blankToNull(?string $text): ?string
    {
        $trimmed = $text === null ? '' : trim($text);

        return $trimmed === '' ? null : $trimmed;
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
            $this->description === null ? null : 'description',
        ]));
    }

    /** The classic tab's form field that carries the reason of this operation (`capture_reason`, `refund_reason`, `cancel_reason`). */
    public function reasonField(): string
    {
        return $this->providerAction() . '_reason';
    }

    public function inputRefusal(): ?PaymentRefusal
    {
        if ($this->clientKey !== null && preg_match(self::KEY_PATTERN, $this->clientKey) !== 1) {
            return PaymentRefusal::invalid('idempotencyKey', 'invalid_key', 'The idempotency key must be 8 to 64 letters, digits, "-" or "_"');
        }

        return $this->textRefusal('reason', $this->reason) ?? $this->textRefusal('description', $this->description);
    }

    /**
     * The text fields of the call: what the shop's reader sees, never more than the API's ceiling, no control characters.
     */
    private function textRefusal(string $field, ?string $text): ?PaymentRefusal
    {
        if ($text === null) {
            return null;
        }
        if (strlen($text) > self::MAX_REASON_LENGTH) {
            return PaymentRefusal::invalid($field, 'too_long', sprintf('The %s is longer than %d characters', $field, self::MAX_REASON_LENGTH));
        }

        return preg_match(self::CONTROL_CHARACTERS, $text) === 1
            ? PaymentRefusal::invalid($field, 'invalid_characters', sprintf('The %s must not contain control characters', $field))
            : null;
    }
}
