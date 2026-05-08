<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

interface ContractTransitionedToPendingEventInterface extends ContractEventInterface
{
    public function getConditions(): array;
}
