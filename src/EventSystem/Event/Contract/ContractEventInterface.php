<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

interface ContractEventInterface extends EventInterface
{
    public function getContract(): PaymentContractInterface;

    public function getContext(): EventContextInterface;
}
