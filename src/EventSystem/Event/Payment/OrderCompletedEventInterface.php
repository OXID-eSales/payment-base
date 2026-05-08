<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Payment;

interface OrderCompletedEventInterface extends PaymentEventInterface
{
    public function getOrderId(): string;

    public function getProviderOrderId(): string;
}
