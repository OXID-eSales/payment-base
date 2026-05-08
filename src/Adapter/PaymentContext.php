<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * Payment context passed to payment handlers
 */
class PaymentContext implements PaymentContextInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly object $basket,
        private readonly object $user,
        private readonly string $paymentMethodId,
        private readonly ?string $providerTransactionId = null,
        private readonly ?string $returnUrl = null,
        private readonly ?string $cancelUrl = null,
        private readonly array $metadata = []
    ) {
    }

    public function getBasket(): object
    {
        return $this->basket;
    }

    public function getUser(): object
    {
        return $this->user;
    }

    public function getPaymentMethodId(): string
    {
        return $this->paymentMethodId;
    }

    public function getProviderTransactionId(): ?string
    {
        return $this->providerTransactionId;
    }

    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function getCancelUrl(): ?string
    {
        return $this->cancelUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
