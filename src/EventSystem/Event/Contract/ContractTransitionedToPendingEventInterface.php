<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

interface ContractTransitionedToPendingEventInterface extends ContractEventInterface
{
    public function getConditions(): array;
}
