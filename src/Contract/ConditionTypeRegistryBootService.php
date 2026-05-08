<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

class ConditionTypeRegistryBootService
{
    public function __construct(ConditionTypeRegistryInterface $registry)
    {
        ContractCondition::setConditionTypeRegistry($registry);
    }
}
