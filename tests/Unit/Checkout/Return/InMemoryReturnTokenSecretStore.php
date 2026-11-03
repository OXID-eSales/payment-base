<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Return;

use OxidEsales\PaymentBase\Checkout\Return\ReturnTokenSecret;
use OxidEsales\PaymentBase\Checkout\Return\ReturnTokenSecretStoreInterface;

final class InMemoryReturnTokenSecretStore implements ReturnTokenSecretStoreInterface
{
    /** @var array<string, ReturnTokenSecret> */
    private array $secrets = [];

    private ?string $currentId = null;

    public function __construct(string ...$secretsOldestFirst)
    {
        foreach ($secretsOldestFirst as $position => $secret) {
            $this->add('key' . $position, $secret);
        }
    }

    /** A newer secret becomes the one tokens are signed with; older ones keep verifying. */
    public function add(string $keyId, string $secret): void
    {
        $this->secrets[$keyId] = new ReturnTokenSecret($keyId, $secret);
        $this->currentId = $keyId;
    }

    public function remove(string $keyId): void
    {
        unset($this->secrets[$keyId]);
    }

    public function current(): ReturnTokenSecret
    {
        return $this->secrets[$this->currentId ?? ''] ?? throw new \RuntimeException('no secret');
    }

    public function find(string $keyId): ?ReturnTokenSecret
    {
        return $this->secrets[$keyId] ?? null;
    }
}
