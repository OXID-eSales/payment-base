<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Payment;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;

readonly class PaymentFailedEvent implements PaymentFailedEventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private string $providerOrderId,
        private string $errorCode,
        private string $errorMessage
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getProviderOrderId(): string
    {
        return $this->providerOrderId;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
