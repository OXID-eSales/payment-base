<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

class ConditionTypeRegistryBootService
{
    public function __construct(ConditionTypeRegistryInterface $registry)
    {
        ContractCondition::setConditionTypeRegistry($registry);
    }
}
