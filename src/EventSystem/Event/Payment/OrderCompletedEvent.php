<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Payment;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;

readonly class OrderCompletedEvent implements OrderCompletedEventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private string $orderId,
        private string $providerOrderId
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getProviderOrderId(): string
    {
        return $this->providerOrderId;
    }
}
