<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\AllowListedClientConfigProviderInterface;

final class AllowListedFakeClientConfigProvider implements AllowListedClientConfigProviderInterface
{
    /**
     * @param array<string, string|bool> $values
     * @param list<string> $names
     */
    public function __construct(
        private readonly string $id,
        private readonly array $values,
        private readonly array $names,
    ) {
    }

    public function providerId(): string
    {
        return $this->id;
    }

    public function publicValues(): array
    {
        return $this->values;
    }

    public function publicValueNames(): array
    {
        return $this->names;
    }
}
