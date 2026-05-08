<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

interface ConditionTypeRegistryInterface
{
    /**
     * Check if a condition type is registered and valid.
     */
    public function isValid(string $type): bool;

    /**
     * Get all registered condition types.
     *
     * @return array<string>
     */
    public function getRegisteredTypes(): array;
}
