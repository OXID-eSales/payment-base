<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

interface ContractFailedEventInterface extends ContractEventInterface
{
    public function getErrorCode(): string;

    public function getErrorMessage(): string;
}
