<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

interface ConditionTypeProviderInterface
{
    /**
     * Return condition types registered by this provider.
     *
     * @return array<string> Condition type strings (e.g., ['payment_authorized', 'fraud_check'])
     */
    public function getConditionTypes(): array;
}
