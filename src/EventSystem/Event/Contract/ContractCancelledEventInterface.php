<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

interface ContractCancelledEventInterface extends ContractTerminatedEventInterface
{
    public function getReason(): string;
}
