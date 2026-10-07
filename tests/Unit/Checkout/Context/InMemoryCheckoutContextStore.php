<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Context;

use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextStoreInterface;

/**
 * The persisted store as an array, so tests can see what a headless checkout
 * wrote under which scope.
 */
final class InMemoryCheckoutContextStore implements CheckoutContextStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, array{userId: ?string, basketId: ?string}> */
    public array $owners = [];

    public int $saves = 0;

    public function load(string $scopeId): array
    {
        return $this->rows[$scopeId] ?? [];
    }

    public function save(string $scopeId, array $data, ?string $userId, ?string $basketId): void
    {
        $this->saves++;
        $this->rows[$scopeId] = $data;
        $this->owners[$scopeId] = ['userId' => $userId, 'basketId' => $basketId];
    }
}
