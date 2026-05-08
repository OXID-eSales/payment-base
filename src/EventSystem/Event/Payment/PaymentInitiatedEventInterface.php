<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Payment;

interface PaymentInitiatedEventInterface extends PaymentEventInterface
{
    public function getPaymentMethodId(): string;

    public function getAmount(): float;

    public function getCurrency(): string;

    public function getReturnUrl(): string;

    public function getCancelUrl(): string;

    public function setProviderRedirectUrl(string $url): void;

    public function getProviderRedirectUrl(): ?string;

    public function setProviderOrderId(string $orderId): void;

    public function getProviderOrderId(): ?string;
}
