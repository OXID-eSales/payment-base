<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Model\AbstractModel;
use OxidEsales\PaymentComponent\Model\ModelInterface;

/**
 * Payment customer entity for provider customer ID mapping.
 *
 * Maps to `oe_payments_customer` table.
 * Links OXID user to payment provider customer (e.g. Stripe cus_xxx).
 *
 * Sprint 45: Stripe Customer lifecycle.
 */
class PaymentCustomer extends AbstractModel implements ModelInterface
{
    private string $userId;
    private ?string $paymentCustomerId;
    private ?string $defaultPaymentMethod;
    private ?string $savedPaymentMethods;
    private bool $billingAgreement;
    private ?DateTimeImmutable $lastPaymentDate;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $userId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->paymentCustomerId = null;
        $this->defaultPaymentMethod = null;
        $this->savedPaymentMethods = null;
        $this->billingAgreement = false;
        $this->lastPaymentDate = null;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): string
    {
        /** @phpstan-ignore-next-line */
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getPaymentCustomerId(): ?string
    {
        return $this->paymentCustomerId;
    }

    public function setPaymentCustomerId(?string $paymentCustomerId): void
    {
        $this->paymentCustomerId = $paymentCustomerId;
    }

    public function getDefaultPaymentMethod(): ?string
    {
        return $this->defaultPaymentMethod;
    }

    public function setDefaultPaymentMethod(?string $defaultPaymentMethod): void
    {
        $this->defaultPaymentMethod = $defaultPaymentMethod;
    }

    public function getSavedPaymentMethods(): ?string
    {
        return $this->savedPaymentMethods;
    }

    public function setSavedPaymentMethods(?string $savedPaymentMethods): void
    {
        $this->savedPaymentMethods = $savedPaymentMethods;
    }

    public function getBillingAgreement(): bool
    {
        return $this->billingAgreement;
    }

    public function setBillingAgreement(bool $billingAgreement): void
    {
        $this->billingAgreement = $billingAgreement;
    }

    public function getLastPaymentDate(): ?DateTimeImmutable
    {
        return $this->lastPaymentDate;
    }

    public function setLastPaymentDate(?DateTimeImmutable $lastPaymentDate): void
    {
        $this->lastPaymentDate = $lastPaymentDate;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'paymentCustomerId' => $this->paymentCustomerId,
            'defaultPaymentMethod' => $this->defaultPaymentMethod,
            'savedPaymentMethods' => $this->savedPaymentMethods,
            'billingAgreement' => $this->billingAgreement,
            'lastPaymentDate' => $this->lastPaymentDate?->format('Y-m-d H:i:s'),
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
        $record = new self(
            /** @phpstan-ignore-next-line */
            $data['id'],
            /** @phpstan-ignore-next-line */
            $data['userId'],
            /** @phpstan-ignore-next-line */
            new DateTimeImmutable($data['createdAt']),
            /** @phpstan-ignore-next-line */
            new DateTimeImmutable($data['updatedAt'])
        );

        if (isset($data['paymentCustomerId']) && is_string($data['paymentCustomerId'])) {
            $record->paymentCustomerId = $data['paymentCustomerId'];
        }

        if (isset($data['defaultPaymentMethod']) && is_string($data['defaultPaymentMethod'])) {
            $record->defaultPaymentMethod = $data['defaultPaymentMethod'];
        }

        if (isset($data['savedPaymentMethods']) && is_string($data['savedPaymentMethods'])) {
            $record->savedPaymentMethods = $data['savedPaymentMethods'];
        }

        if (isset($data['billingAgreement'])) {
            $record->billingAgreement = (bool) $data['billingAgreement'];
        }

        if (isset($data['lastPaymentDate']) && is_string($data['lastPaymentDate'])) {
            $record->lastPaymentDate = new DateTimeImmutable($data['lastPaymentDate']);
        }

        return $record;
    }
}
