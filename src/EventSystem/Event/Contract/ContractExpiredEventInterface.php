<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

interface ContractExpiredEventInterface extends ContractTerminatedEventInterface
{
    public function getExpirationTime(): int;
}
