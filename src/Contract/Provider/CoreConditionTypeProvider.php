<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract\Provider;

use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;
use OxidEsales\PaymentComponent\Contract\ContractCondition;

class CoreConditionTypeProvider implements ConditionTypeProviderInterface
{
    public function getConditionTypes(): array
    {
        return [
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            ContractCondition::TYPE_FRAUD_CHECK,
            ContractCondition::TYPE_COMPLIANCE_CHECK,
            ContractCondition::TYPE_ADDRESS_VALIDATED,
        ];
    }
}
