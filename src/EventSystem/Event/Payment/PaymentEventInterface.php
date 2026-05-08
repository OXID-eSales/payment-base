<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Payment;

use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

interface PaymentEventInterface extends EventInterface
{
    public function getContext(): EventContextInterface;
}
