<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Payment;

use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;

readonly class PaymentCapturedEvent implements PaymentCapturedEventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private string $authorizationId,
        private string $captureId,
        private float $capturedAmount,
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

    public function getCaptureId(): string
    {
        return $this->captureId;
    }

    public function getCapturedAmount(): float
    {
        return $this->capturedAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
