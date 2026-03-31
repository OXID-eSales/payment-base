<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter;

/**
 * Interface for payment context passed to payment handlers.
 */
interface PaymentContextInterface
{
    public function getBasket(): object;

    public function getUser(): object;

    public function getPaymentMethodId(): string;

    public function getProviderTransactionId(): ?string;

    public function getReturnUrl(): ?string;

    public function getCancelUrl(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    public function getMetadataValue(string $key, mixed $default = null): mixed;
}
