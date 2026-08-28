<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use DateTime;
use DateInterval;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use LogicException;
use OxidEsales\PaymentBase\Model\AbstractModel;

class PaymentContract extends AbstractModel implements PaymentContractInterface
{
    private int $shopId;
    private string $userId;
    private ?string $orderId = null;
    private ContractState $state;
    private BasketSnapshot $basketSnapshot;

    /**
     * @var array<int, ContractCondition>
     */
    private array $conditions = [];
    private ?DateTimeInterface $expiresAt = null;
    private DateTimeInterface $createdAt;
    private DateTimeInterface $updatedAt;
    private ?DateTimeInterface $committedAt = null;
    private ?DateTimeInterface $fulfilledAt = null;

    // Sprint 8: Capture/Refund tracking (migrated from order_state).
    // Sprint 01a (2026-05-19): extracted into CaptureRefundTracker to keep
    // this aggregate below its PHPMD class-complexity threshold.
    private CaptureRefundTracker $refundTracking;

    private ?string $provider = null;
    private ?string $providerOrderId = null;
    private ?string $providerRedirectUrl = null;

    /**
     * Arbitrary metadata storage for provider-specific data.
     * @var array<string, mixed>
     */
    private array $metadata = [];

    public function __construct(
        int $shopId,
        string $userId,
        BasketSnapshot $basketSnapshot,
        ?string $id = null
    ) {
        $this->id = $id ?? bin2hex(random_bytes(16));
        $this->shopId = $shopId;
        $this->userId = $userId;
        $this->basketSnapshot = $basketSnapshot;
        $this->state = ContractState::draft();
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->expiresAt = (new DateTime())->add(new DateInterval('PT24H'));
        $this->refundTracking = new CaptureRefundTracker();
    }

    public function addCondition(ContractCondition $condition): void
    {
        if (!$this->state->isDraft()) {
            throw new DomainException('Cannot add conditions after DRAFT state');
        }

        $this->conditions[] = $condition;
        $this->touch();
    }

    /**
     * Transition contract from DRAFT to NOT_FINISHED state.
     *
     * This is the early order creation step where the order is created
     * and linked to the contract before payment processing begins.
     *
     * @param string $orderId The ID of the created order
     * @throws DomainException if not in DRAFT state or orderId is empty
     */
    public function transitionToNotFinished(string $orderId): void
    {
        if (!$this->state->isDraft()) {
            throw new DomainException('Can only transition to NOT_FINISHED from DRAFT state');
        }

        if ($orderId === '') {
            throw new DomainException('Order ID is required for NOT_FINISHED transition');
        }

        if (empty($this->conditions)) {
            throw new DomainException('Cannot transition to NOT_FINISHED without conditions');
        }

        $this->orderId = $orderId;
        $this->state = ContractState::notFinished();
        $this->touch();
    }

    public function transitionToPending(): void
    {
        if (!$this->state->isNotFinished()) {
            throw new DomainException('Can only transition to PENDING from NOT_FINISHED state');
        }

        if (empty($this->conditions)) {
            throw new DomainException('Cannot transition to PENDING without conditions');
        }

        $this->state = ContractState::pending();
        $this->touch();
    }

    /**
     * Transition contract to AUTHORIZED state (for manual capture mode).
     *
     * This indicates the payment has been authorized by the provider
     * but funds have not yet been captured/transferred.
     *
     * @throws DomainException if not in PENDING state
     */
    public function authorize(): void
    {
        if (!$this->state->isPending()) {
            throw new DomainException('Can only transition to AUTHORIZED from PENDING state');
        }

        $this->state = ContractState::authorized();
        $this->touch();
    }

    /**
     * Capture an authorized payment, transitioning to READY_TO_COMMIT.
     *
     * This is called when a manual capture is executed on an authorized payment.
     *
     * @throws DomainException if not in AUTHORIZED state
     */
    public function captureAuthorization(): void
    {
        if (!$this->state->isAuthorized()) {
            throw new DomainException('Can only capture authorization from AUTHORIZED state');
        }

        $this->state = ContractState::readyToCommit();
        $this->touch();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fulfillCondition(string $type, array $data = []): void
    {
        $condition = $this->findCondition($type);

        if ($condition === null) {
            throw new DomainException("Condition type '{$type}' not found");
        }

        $condition->fulfill($data);
        $this->touch();

        if ($this->areAllConditionsFulfilled() && $this->state->isPending()) {
            $this->state = ContractState::readyToCommit();
        }
    }

    public function failCondition(string $type, string $reason): void
    {
        $condition = $this->findCondition($type);

        if ($condition === null) {
            throw new DomainException("Condition type '{$type}' not found");
        }

        $condition->fail($reason);
        $this->fail("Condition '{$type}' failed: {$reason}");
        $this->touch();
    }

    public function areAllConditionsFulfilled(): bool
    {
        if (empty($this->conditions)) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->isFulfilled()) {
                return false;
            }
        }

        return true;
    }

    public function commitToOrder(string $orderId): void
    {
        if (!$this->state->isReadyToCommit()) {
            throw new DomainException('Contract must be in READY_TO_COMMIT state to commit');
        }

        if (!$this->areAllConditionsFulfilled()) {
            throw new DomainException('Cannot commit contract with unfulfilled conditions');
        }

        $this->orderId = $orderId;
        $this->state = ContractState::committed();
        $this->committedAt = new DateTime();
        $this->touch();
    }

    public function fulfill(): void
    {
        if (!$this->state->isCommitted()) {
            throw new DomainException('Contract must be COMMITTED before fulfillment');
        }

        $this->state = ContractState::fulfilled();
        $this->fulfilledAt = new DateTime();
        $this->touch();
    }

    public function cancel(string $reason = ''): void
    {
        if ($this->state->isTerminal()) {
            throw new DomainException('Cannot cancel a terminal state contract');
        }

        $this->state = ContractState::cancelled();
        $this->touch();
    }

    public function fail(string $reason): void
    {
        if ($this->state->isTerminal()) {
            throw new DomainException('Cannot fail a terminal state contract');
        }

        $this->state = ContractState::failed();
        $this->touch();
    }

    public function expire(): void
    {
        if ($this->state->isTerminal()) {
            throw new DomainException('Cannot expire a terminal state contract');
        }

        // STRP-168: `committed` is not terminal, so the guard above let a
        // contract whose payment had already been taken be transitioned to
        // EXPIRED — silently rewriting settled payment history. The deadline
        // stored on a contract says nothing about money that has already moved.
        if ($this->state->isCommitted()) {
            throw new DomainException('Cannot expire a committed contract: its payment has already been taken');
        }

        $this->state = ContractState::expired();
        $this->touch();
    }

    public function setProvider(string $provider, string $providerOrderId, ?string $redirectUrl = null): void
    {
        $this->provider = $provider;
        $this->providerOrderId = $providerOrderId;
        $this->providerRedirectUrl = $redirectUrl;
        $this->touch();
    }

    public function getId(): string
    {
        if ($this->id === null) {
            throw new LogicException('Contract ID should never be null');
        }
        return $this->id;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getState(): ContractState
    {
        return $this->state;
    }

    public function getStateValue(): string
    {
        return $this->state->getValue();
    }

    public function getBasketSnapshot(): BasketSnapshot
    {
        return $this->basketSnapshot;
    }

    /**
     * @return array<int, ContractCondition>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    public function getProviderRedirectUrl(): ?string
    {
        return $this->providerRedirectUrl;
    }

    /**
     * Set a metadata value.
     *
     * Used to store provider-specific data like delivery address hash.
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
        $this->touch();
    }

    /**
     * Get a metadata value.
     *
     * @return mixed The stored value, or null if not set
     */
    public function getMetadata(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * Get all metadata.
     *
     * @return array<string, mixed>
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }

    public function getExpiresAt(): ?DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getFulfilledAt(): ?DateTimeInterface
    {
        return $this->fulfilledAt;
    }

    // Sprint 8: Capture/Refund tracking methods.
    // Sprint 01a (2026-05-19): now delegates to CaptureRefundTracker.

    public function getCapturedAmount(): ?float
    {
        return $this->refundTracking->getCapturedAmount();
    }

    public function setCapturedAmount(float $amount): void
    {
        $this->refundTracking->setCapturedAmount($this->state, $amount);
        $this->touch();
    }

    public function getRefundedAmount(): ?float
    {
        return $this->refundTracking->getRefundedAmount();
    }

    public function addRefundedAmount(float $amount): void
    {
        $this->refundTracking->addRefundedAmount($this->state, $amount);
        $this->touch();
    }

    public function getCapturedAt(): ?DateTimeInterface
    {
        return $this->refundTracking->getCapturedAt();
    }

    public function setCapturedAt(DateTimeInterface $date): void
    {
        $this->refundTracking->setCapturedAt($date);
        $this->touch();
    }

    public function getRefundedAt(): ?DateTimeInterface
    {
        return $this->refundTracking->getRefundedAt();
    }

    public function setRefundedAt(DateTimeInterface $date): void
    {
        $this->refundTracking->setRefundedAt($date);
        $this->touch();
    }

    public function getRemainingRefundableAmount(): ?float
    {
        return $this->refundTracking->getRemainingRefundableAmount();
    }

    public function isFullyRefunded(): bool
    {
        return $this->refundTracking->isFullyRefunded();
    }

    public function isExpired(): bool
    {
        if ($this->state->isTerminal()) {
            return false;
        }

        return $this->expiresAt !== null && $this->expiresAt < new DateTime();
    }

    public function isInState(string $state): bool
    {
        return $this->state->getValue() === $state;
    }

    public function getAmount(): float
    {
        return $this->basketSnapshot->getTotalGross();
    }

    public function getCurrency(): string
    {
        return $this->basketSnapshot->getCurrency();
    }

    private function findCondition(string $type): ?ContractCondition
    {
        foreach ($this->conditions as $condition) {
            if ($condition->getType() === $type) {
                return $condition;
            }
        }

        return null;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTime();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shopId' => $this->shopId,
            'userId' => $this->userId,
            'orderId' => $this->orderId,
            'state' => $this->state->getValue(),
            'basketSnapshot' => $this->basketSnapshot->toArray(),
            'conditions' => array_map(fn($c) => $c->toArray(), $this->conditions),
            'provider' => $this->provider,
            'providerOrderId' => $this->providerOrderId,
            'providerRedirectUrl' => $this->providerRedirectUrl,
            'metadata' => $this->metadata,
            'expiresAt' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt->format('Y-m-d H:i:s'),
            'committedAt' => $this->committedAt?->format('Y-m-d H:i:s'),
            'fulfilledAt' => $this->fulfilledAt?->format('Y-m-d H:i:s'),
            ...$this->refundTracking->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $contract = new self(
            shopId: self::extractShopId($data),
            userId: self::extractUserId($data),
            basketSnapshot: self::extractBasketSnapshot($data),
            id: self::extractOptionalString($data, 'id')
        );

        $contract->orderId = self::extractOptionalString($data, 'orderId');
        $contract->state = self::extractState($data);
        $contract->provider = self::extractOptionalString($data, 'provider');
        $contract->providerOrderId = self::extractOptionalString($data, 'providerOrderId');
        $contract->providerRedirectUrl = self::extractOptionalString($data, 'providerRedirectUrl');
        $contract->metadata = self::extractMetadata($data);
        $contract->conditions = self::extractConditions($data);
        self::validateStateConsistency($contract->state, $contract->conditions);
        $contract->expiresAt = self::extractOptionalDateTime($data, 'expiresAt');
        $contract->createdAt = self::extractDateTime($data, 'createdAt');
        $contract->updatedAt = self::extractDateTime($data, 'updatedAt');
        $contract->fulfilledAt = self::extractOptionalDateTime($data, 'fulfilledAt');
        $contract->refundTracking = CaptureRefundTracker::fromArray($data);

        return $contract;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractShopId(array $data): int
    {
        if (!isset($data['shopId'])) {
            throw new InvalidArgumentException('shopId is required');
        }
        if (is_int($data['shopId'])) {
            return $data['shopId'];
        }
        if (is_string($data['shopId'])) {
            return (int) $data['shopId'];
        }
        throw new InvalidArgumentException('shopId must be an integer');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractUserId(array $data): string
    {
        if (!isset($data['userId']) || !is_string($data['userId'])) {
            throw new InvalidArgumentException('userId must be a string');
        }
        return $data['userId'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractBasketSnapshot(array $data): BasketSnapshot
    {
        if (!isset($data['basketSnapshot']) || !is_array($data['basketSnapshot'])) {
            throw new InvalidArgumentException('basketSnapshot must be an array');
        }
        /** @var array<string, mixed> $basketData */
        $basketData = $data['basketSnapshot'];
        return BasketSnapshot::fromArray($basketData);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractState(array $data): ContractState
    {
        if (!isset($data['state']) || !is_string($data['state'])) {
            throw new InvalidArgumentException('state must be a string');
        }
        return ContractState::fromValue($data['state']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, ContractCondition>
     */
    private static function extractConditions(array $data): array
    {
        if (!isset($data['conditions']) || !is_array($data['conditions'])) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $conditionsData */
        $conditionsData = array_filter($data['conditions'], 'is_array');
        return array_values(array_map(
            fn(array $c): ContractCondition => ContractCondition::fromArray($c),
            $conditionsData
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractOptionalString(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractDateTime(array $data, string $key): DateTimeInterface
    {
        if (isset($data[$key]) && is_string($data[$key])) {
            return new DateTime($data[$key]);
        }
        return new DateTime();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractOptionalDateTime(array $data, string $key): ?DateTimeInterface
    {
        if (isset($data[$key]) && is_string($data[$key])) {
            return new DateTime($data[$key]);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function extractMetadata(array $data): array
    {
        if (!isset($data['metadata']) || !is_array($data['metadata'])) {
            return [];
        }
        /** @var array<string, mixed> */
        return $data['metadata'];
    }

    /**
     * Sprint 68a (H5): Detect impossible state/condition combinations.
     *
     * Defensive warning — does NOT block (DB is source of truth).
     * Inconsistency indicates a bug or data corruption.
     *
     * @param array<int, ContractCondition> $conditions
     */
    private static function validateStateConsistency(ContractState $state, array $conditions): void
    {
        if (!$state->isFulfilled() || empty($conditions)) {
            return;
        }

        $unfulfilled = array_filter(
            $conditions,
            fn(ContractCondition $c): bool => !$c->isFulfilled()
        );

        if (!empty($unfulfilled)) {
            trigger_error(
                sprintf(
                    'PaymentContract state/condition inconsistency: state=%s but %d conditions unfulfilled',
                    $state->getValue(),
                    count($unfulfilled)
                ),
                E_USER_WARNING
            );
        }
    }
}
