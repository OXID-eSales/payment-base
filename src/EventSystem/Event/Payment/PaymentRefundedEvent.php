<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Payment;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;

readonly class PaymentRefundedEvent implements PaymentRefundedEventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private string $refundId,
        private string $providerOrderId,
        private float $amount,
        private string $currency,
        private string $orderId
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getRefundId(): string
    {
        return $this->refundId;
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

    public function getOrderId(): string
    {
        return $this->orderId;
    }
}
