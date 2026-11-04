<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Model;

abstract class AbstractModel implements ModelInterface
{
    protected ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
