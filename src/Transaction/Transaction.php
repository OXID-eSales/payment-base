<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Transaction;

use DateTime;
use DateTimeImmutable;

/**
 * Transaction entity representing a payment transaction
 *
 * Immutable value object for transaction data.
 *
 * @SuppressWarnings(PHPMD)
 */
class Transaction
{
    private string $id;
    private int $shopId;
    private string $orderId;
    private ?string $contractId;
    private string $provider;
    private ?string $providerOrderId;
    private ?string $transactionId;
    private string $type;
    private string $status;
    private float $amount;
    private string $currency;
    private ?string $paymentMethodId;
    private ?string $paymentMethodType;
    private ?string $parentTransactionId;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        int $shopId,
        string $orderId,
        ?string $contractId,
        string $provider,
        string $type,
        string $status,
        float $amount,
        string $currency
    ) {
        $this->id = $id;
        $this->shopId = $shopId;
        $this->orderId = $orderId;
        $this->contractId = $contractId;
        $this->provider = $provider;
        $this->type = $type;
        $this->status = $status;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->providerOrderId = null;
        $this->transactionId = null;
        $this->paymentMethodId = null;
        $this->paymentMethodType = null;
        $this->parentTransactionId = null;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    public function setProviderOrderId(?string $providerOrderId): void
    {
        $this->providerOrderId = $providerOrderId;
        $this->updateTimestamp();
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->updateTimestamp();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updateTimestamp();
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaymentMethodId(): ?string
    {
        return $this->paymentMethodId;
    }

    public function setPaymentMethodId(?string $paymentMethodId): void
    {
        $this->paymentMethodId = $paymentMethodId;
        $this->updateTimestamp();
    }

    public function getPaymentMethodType(): ?string
    {
        return $this->paymentMethodType;
    }

    public function setPaymentMethodType(?string $paymentMethodType): void
    {
        $this->paymentMethodType = $paymentMethodType;
        $this->updateTimestamp();
    }

    public function getParentTransactionId(): ?string
    {
        return $this->parentTransactionId;
    }

    public function setParentTransactionId(?string $parentTransactionId): void
    {
        $this->parentTransactionId = $parentTransactionId;
        $this->updateTimestamp();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function updateTimestamp(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shopId' => $this->shopId,
            'orderId' => $this->orderId,
            'contractId' => $this->contractId,
            'provider' => $this->provider,
            'providerOrderId' => $this->providerOrderId,
            'transactionId' => $this->transactionId,
            'type' => $this->type,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paymentMethodId' => $this->paymentMethodId,
            'paymentMethodType' => $this->paymentMethodType,
            'parentTransactionId' => $this->parentTransactionId,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @phpstan-ignore-next-line */
        $transaction = new self(
            /** @phpstan-ignore-next-line */
            $data['id'],
            /** @phpstan-ignore-next-line */
            $data['shopId'],
            /** @phpstan-ignore-next-line */
            $data['orderId'],
            /** @phpstan-ignore-next-line */
            $data['contractId'] ?? null,
            /** @phpstan-ignore-next-line */
            $data['provider'],
            /** @phpstan-ignore-next-line */
            $data['type'],
            /** @phpstan-ignore-next-line */
            $data['status'],
            /** @phpstan-ignore-next-line */
            $data['amount'],
            /** @phpstan-ignore-next-line */
            $data['currency']
        );

        if (isset($data['providerOrderId'])) {
            /** @phpstan-ignore-next-line */
            $transaction->providerOrderId = $data['providerOrderId'];
        }

        if (isset($data['transactionId'])) {
            /** @phpstan-ignore-next-line */
            $transaction->transactionId = $data['transactionId'];
        }

        if (isset($data['paymentMethodId'])) {
            /** @phpstan-ignore-next-line */
            $transaction->paymentMethodId = $data['paymentMethodId'];
        }

        if (isset($data['paymentMethodType'])) {
            /** @phpstan-ignore-next-line */
            $transaction->paymentMethodType = $data['paymentMethodType'];
        }

        if (isset($data['parentTransactionId'])) {
            /** @phpstan-ignore-next-line */
            $transaction->parentTransactionId = $data['parentTransactionId'];
        }

        if (isset($data['createdAt'])) {
            /** @phpstan-ignore-next-line */
            $transaction->createdAt = new DateTimeImmutable($data['createdAt']);
        }

        if (isset($data['updatedAt'])) {
            /** @phpstan-ignore-next-line */
            $transaction->updatedAt = new DateTimeImmutable($data['updatedAt']);
        }

        return $transaction;
    }
}
