<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

interface ContractCommittedEventInterface extends ContractEventInterface
{
    public function getOrderId(): string;
}
