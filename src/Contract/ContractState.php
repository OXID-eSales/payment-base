<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use InvalidArgumentException;

class ContractState
{
    private const VALID_STATES = [
        'draft',
        'not_finished',
        'pending',
        'authorized',
        'ready_to_commit',
        'committed',
        'fulfilled',
        'cancelled',
        'expired',
        'failed',
    ];

    private const TERMINAL_STATES = [
        'fulfilled',
        'cancelled',
        'expired',
        'failed',
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::VALID_STATES, true)) {
            throw new InvalidArgumentException("Invalid contract state: {$value}");
        }

        $this->value = $value;
    }

    public static function draft(): self
    {
        return new self('draft');
    }

    public static function notFinished(): self
    {
        return new self('not_finished');
    }

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function authorized(): self
    {
        return new self('authorized');
    }

    public static function readyToCommit(): self
    {
        return new self('ready_to_commit');
    }

    public static function committed(): self
    {
        return new self('committed');
    }

    public static function fulfilled(): self
    {
        return new self('fulfilled');
    }

    public static function cancelled(): self
    {
        return new self('cancelled');
    }

    public static function expired(): self
    {
        return new self('expired');
    }

    public static function failed(): self
    {
        return new self('failed');
    }

    public static function fromValue(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Invalid contract state: empty string');
        }
        return new self($value);
    }

    public function isDraft(): bool
    {
        return $this->value === 'draft';
    }

    public function isNotFinished(): bool
    {
        return $this->value === 'not_finished';
    }

    public function isPending(): bool
    {
        return $this->value === 'pending';
    }

    public function isAuthorized(): bool
    {
        return $this->value === 'authorized';
    }

    public function isReadyToCommit(): bool
    {
        return $this->value === 'ready_to_commit';
    }

    public function isCommitted(): bool
    {
        return $this->value === 'committed';
    }

    public function isFulfilled(): bool
    {
        return $this->value === 'fulfilled';
    }

    public function isCancelled(): bool
    {
        return $this->value === 'cancelled';
    }

    public function isExpired(): bool
    {
        return $this->value === 'expired';
    }

    public function isFailed(): bool
    {
        return $this->value === 'failed';
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_STATES, true);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(ContractState $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
