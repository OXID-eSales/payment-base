<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;

/**
 * An in-memory session, so tests can assert what a checkout left behind in it.
 */
final class RecordingSessionAdapter implements SessionAdapterInterface
{
    /** @var array<string, mixed> */
    private array $variables = [];

    public function getSessionId(): string
    {
        return 'session-1';
    }

    public function getBasket(): ?object
    {
        return null;
    }

    public function setVariable(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
    }

    public function getVariable(string $name): mixed
    {
        return $this->variables[$name] ?? null;
    }

    public function setBasket(object $basket): void
    {
    }

    public function setUser(object $user): void
    {
    }
}
