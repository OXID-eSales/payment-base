<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Payment;

interface PaymentFailedEventInterface extends PaymentEventInterface
{
    public function getProviderOrderId(): string;

    public function getErrorCode(): string;

    public function getErrorMessage(): string;
}
