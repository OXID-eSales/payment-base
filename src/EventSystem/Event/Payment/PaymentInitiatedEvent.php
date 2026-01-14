<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Payment;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;

class PaymentInitiatedEvent implements PaymentInitiatedEventInterface
{
    private ?string $providerRedirectUrl = null;
    private ?string $providerOrderId = null;

    public function __construct(
        private readonly EventContextInterface $context,
        private readonly string $paymentMethodId,
        private readonly float $amount,
        private readonly string $currency,
        private readonly string $returnUrl,
        private readonly string $cancelUrl
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getPaymentMethodId(): string
    {
        return $this->paymentMethodId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getReturnUrl(): string
    {
        return $this->returnUrl;
    }

    public function getCancelUrl(): string
    {
        return $this->cancelUrl;
    }

    public function setProviderRedirectUrl(string $url): void
    {
        $this->providerRedirectUrl = $url;
    }

    public function getProviderRedirectUrl(): ?string
    {
        return $this->providerRedirectUrl;
    }

    public function setProviderOrderId(string $orderId): void
    {
        $this->providerOrderId = $orderId;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }
}
