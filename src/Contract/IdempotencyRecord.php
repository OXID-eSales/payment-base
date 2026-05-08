<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Model\AbstractModel;
use OxidEsales\PaymentBase\Model\ModelInterface;

/**
 * Idempotency record entity for preventing duplicate API operations.
 *
 * Maps to `oe_payments_idempotency` table.
 * Tracks capture/refund operations to prevent duplicate charges.
 *
 * Sprint 42: Idempotency implementation.
 *
 */
class IdempotencyRecord extends AbstractModel implements ModelInterface
{
    private string $key;
    private string $orderId;
    private string $operation;
    private ?string $result;
    private string $status;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $expiresAt;

    public function __construct(
        string $id,
        string $key,
        string $orderId,
        string $operation,
        string $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt
    ) {
        $this->id = $id;
        $this->key = $key;
        $this->orderId = $orderId;
        $this->operation = $operation;
        $this->result = null;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): string
    {
        /** @phpstan-ignore-next-line */
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): void
    {
        $this->result = $result;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'orderId' => $this->orderId,
            'operation' => $this->operation,
            'result' => $this->result,
            'status' => $this->status,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'expiresAt' => $this->expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @phpstan-ignore-next-line */
        $record = new self(
            /** @phpstan-ignore-next-line */
            $data['id'],
            /** @phpstan-ignore-next-line */
            $data['key'],
            /** @phpstan-ignore-next-line */
            $data['orderId'],
            /** @phpstan-ignore-next-line */
            $data['operation'],
            /** @phpstan-ignore-next-line */
            $data['status'],
            /** @phpstan-ignore-next-line */
            new DateTimeImmutable($data['createdAt']),
            /** @phpstan-ignore-next-line */
            new DateTimeImmutable($data['expiresAt'])
        );

        if (isset($data['result'])) {
            /** @phpstan-ignore-next-line */
            $record->result = $data['result'];
        }

        return $record;
    }
}
