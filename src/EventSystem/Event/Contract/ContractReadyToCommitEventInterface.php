<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

interface ContractReadyToCommitEventInterface extends ContractEventInterface
{
    public function getPaymentProviderData(): array;
}
