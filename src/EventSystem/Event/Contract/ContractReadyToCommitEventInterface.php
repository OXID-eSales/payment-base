<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

interface ContractReadyToCommitEventInterface extends ContractEventInterface
{
    public function getPaymentProviderData(): array;
}
