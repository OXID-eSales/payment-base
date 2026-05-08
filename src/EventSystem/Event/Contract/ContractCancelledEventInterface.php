<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

interface ContractCancelledEventInterface extends ContractTerminatedEventInterface
{
    public function getReason(): string;
}
