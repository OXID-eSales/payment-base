<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Payment;

use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;

readonly class PaymentAuthorizedEvent implements PaymentAuthorizedEventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private string $authorizationId,
        private string $providerOrderId,
        private float $amount,
        private string $currency
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getAuthorizationId(): string
    {
        return $this->authorizationId;
    }

    public function getProviderOrderId(): string
    {
        return $this->providerOrderId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
