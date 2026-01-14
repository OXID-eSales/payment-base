<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

/**
 * Request-scoped data cache for event processing.
 * Prevents multiple DB queries by caching basket, user, and contract references.
 */
interface EventContextInterface
{
    public function set(string $key, mixed $value): void;

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function all(): array;

    public function getBasket(): ?object;

    public function getUser(): ?object;

    public function getOrderId(): ?string;

    public function setContract(PaymentContractInterface $contract): void;

    public function getContract(): ?PaymentContractInterface;

    public function hasContract(): bool;
}
