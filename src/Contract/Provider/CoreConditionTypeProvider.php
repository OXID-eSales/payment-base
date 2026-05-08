<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract\Provider;

use OxidEsales\PaymentBase\Contract\ConditionTypeProviderInterface;
use OxidEsales\PaymentBase\Contract\ContractCondition;

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
