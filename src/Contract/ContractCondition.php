<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

use DateTime;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;

class ContractCondition
{
    public const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
    public const TYPE_FRAUD_CHECK = 'fraud_check';
    public const TYPE_COMPLIANCE_CHECK = 'compliance_check';
    public const TYPE_ADDRESS_VALIDATED = 'address_validated';

    public const STATUS_PENDING = 'pending';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_FAILED = 'failed';

    private static ?ConditionTypeRegistryInterface $registry = null;

    private string $type;
    private string $status;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];
    private ?DateTimeInterface $fulfilledAt = null;
    private ?string $failureReason = null;

    public function __construct(string $type)
    {
        $this->validateType($type);
        $this->type = $type;
        $this->status = self::STATUS_PENDING;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fulfill(array $data = []): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new DomainException("Condition '{$this->type}' is already fulfilled");
        }

        $this->status = self::STATUS_FULFILLED;
        $this->data = $data;
        $this->fulfilledAt = new DateTime();
    }

    public function fail(string $reason): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new DomainException("Cannot fail a fulfilled condition");
        }

        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getFulfilledAt(): ?DateTimeInterface
    {
        return $this->fulfilledAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * Inject the registry at boot time (called from DI container).
     * When null, falls back to hardcoded types for backward compatibility.
     */
    public static function setConditionTypeRegistry(?ConditionTypeRegistryInterface $registry): void
    {
        self::$registry = $registry;
    }

    private function validateType(string $type): void
    {
        if (self::$registry !== null) {
            if (!self::$registry->isValid($type)) {
                throw new InvalidArgumentException("Invalid condition type: {$type}");
            }
            return;
        }

        // Fallback: hardcoded types (backward compatibility when no DI container)
        $validTypes = [
            self::TYPE_PAYMENT_AUTHORIZED,
            self::TYPE_FRAUD_CHECK,
            self::TYPE_COMPLIANCE_CHECK,
            self::TYPE_ADDRESS_VALIDATED,
        ];

        if (!in_array($type, $validTypes, true)) {
            throw new InvalidArgumentException("Invalid condition type: {$type}");
        }
    }

    /**
     * Factory method for payment authorized condition
     */
    public static function paymentAuthorized(): self
    {
        return new self(self::TYPE_PAYMENT_AUTHORIZED);
    }

    /**
     * Factory method for fraud check condition
     */
    public static function fraudCheck(): self
    {
        return new self(self::TYPE_FRAUD_CHECK);
    }

    /**
     * Factory method for compliance check condition
     */
    public static function complianceCheck(): self
    {
        return new self(self::TYPE_COMPLIANCE_CHECK);
    }

    /**
     * Factory method for address validated condition
     */
    public static function addressValidated(): self
    {
        return new self(self::TYPE_ADDRESS_VALIDATED);
    }

    /**
     * Factory method for agent identity verified condition
     */
    public static function agentIdentityVerified(): self
    {
        return new self(Provider\AgentConditionTypeProvider::TYPE_AGENT_IDENTITY_VERIFIED);
    }

    /**
     * Factory method for agent consent confirmed condition
     */
    public static function agentConsentConfirmed(): self
    {
        return new self(Provider\AgentConditionTypeProvider::TYPE_AGENT_CONSENT_CONFIRMED);
    }

    /**
     * Factory method for fulfilled fraud check condition (convenience for testing)
     */
    public static function fraudCheckPassed(): self
    {
        $condition = new self(self::TYPE_FRAUD_CHECK);
        $condition->fulfill(['passed' => true]);
        return $condition;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'data' => $this->data,
            'fulfilledAt' => $this->fulfilledAt?->format('Y-m-d H:i:s'),
            'failureReason' => $this->failureReason,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['type'])) {
            throw new InvalidArgumentException('type must be a string');
        }
        if (!is_string($data['status'])) {
            throw new InvalidArgumentException('status must be a string');
        }

        $condition = new self($data['type']);
        $condition->status = $data['status'];

        /** @var array<string, mixed> $conditionData */
        $conditionData = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
        $condition->data = $conditionData;

        $condition->failureReason = isset($data['failureReason']) && is_string($data['failureReason']) ? $data['failureReason'] : null;

        if (isset($data['fulfilledAt']) && is_string($data['fulfilledAt'])) {
            $condition->fulfilledAt = new DateTime($data['fulfilledAt']);
        }

        return $condition;
    }
}
