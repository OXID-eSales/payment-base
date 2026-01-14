<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Payment;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;

interface PaymentEventInterface extends EventInterface
{
    public function getContext(): EventContextInterface;
}
