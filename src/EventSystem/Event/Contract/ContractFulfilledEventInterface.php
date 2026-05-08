<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

interface ContractFulfilledEventInterface extends ContractEventInterface
{
    public function getOrderId(): string;
}
