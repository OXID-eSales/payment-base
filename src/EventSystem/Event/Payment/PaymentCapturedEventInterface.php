<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Payment;

interface PaymentCapturedEventInterface extends PaymentEventInterface
{
    public function getAuthorizationId(): string;

    public function getCaptureId(): string;

    public function getCapturedAmount(): float;

    public function getCurrency(): string;
}
