<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Model;

interface ModelInterface
{
    public function getId(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
