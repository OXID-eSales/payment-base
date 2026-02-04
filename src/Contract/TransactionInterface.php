<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

use DateTimeImmutable;

/**
 * Interface for payment transaction entities
 */
interface TransactionInterface
{
    public function getId(): string;

    public function getShopId(): int;

    public function getOrderId(): string;

    public function getContractId(): ?string;

    public function getProvider(): string;

    public function getProviderOrderId(): ?string;

    public function setProviderOrderId(?string $providerOrderId): void;

    public function getTransactionId(): ?string;

    public function setTransactionId(?string $transactionId): void;

    public function getType(): string;

    public function getStatus(): string;

    public function setStatus(string $status): void;

    public function getAmount(): float;

    public function getCurrency(): string;

    public function getPaymentMethodId(): ?string;

    public function setPaymentMethodId(?string $paymentMethodId): void;

    public function getPaymentMethodType(): ?string;

    public function setPaymentMethodType(?string $paymentMethodType): void;

    public function getParentTransactionId(): ?string;

    public function setParentTransactionId(?string $parentTransactionId): void;

    public function getCreatedAt(): DateTimeImmutable;

    public function getUpdatedAt(): DateTimeImmutable;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
