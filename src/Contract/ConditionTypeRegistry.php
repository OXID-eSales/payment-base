<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

class ConditionTypeRegistry implements ConditionTypeRegistryInterface
{
    /** @var array<string, true> */
    private array $types = [];

    /**
     * @param iterable<ConditionTypeProviderInterface> $providers Collected via !tagged_iterator
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            foreach ($provider->getConditionTypes() as $type) {
                $this->types[$type] = true;
            }
        }
    }

    public function isValid(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function getRegisteredTypes(): array
    {
        return array_keys($this->types);
    }
}
