<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Payment;

interface OrderCreatedEventInterface extends PaymentEventInterface
{
    public function getOrderId(): string;

    public function getContractId(): string;
}
