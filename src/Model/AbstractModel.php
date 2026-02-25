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

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
