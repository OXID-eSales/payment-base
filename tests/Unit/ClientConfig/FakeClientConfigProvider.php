<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderInterface;

final class FakeClientConfigProvider implements ClientConfigProviderInterface
{
    /**
     * @param array<string, string|bool> $values
     */
    public function __construct(private readonly string $id, private readonly array $values)
    {
    }

    public function providerId(): string
    {
        return $this->id;
    }

    public function publicValues(): array
    {
        return $this->values;
    }
}
