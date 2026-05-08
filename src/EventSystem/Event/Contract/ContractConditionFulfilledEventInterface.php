<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

interface ContractConditionFulfilledEventInterface extends ContractEventInterface
{
    public function getConditionType(): string;

    public function getConditionData(): array;
}
