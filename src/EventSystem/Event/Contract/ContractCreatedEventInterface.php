<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

interface ContractCreatedEventInterface extends ContractEventInterface
{
    public function getContractId(): string;

    public function getContractState(): string;
}
