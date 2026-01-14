<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Model;

abstract class AbstractModel implements ModelInterface
{
    protected ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    protected function generateId(string $prefix = 'id'): string
    {
        return uniqid($prefix . '_', true);
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
