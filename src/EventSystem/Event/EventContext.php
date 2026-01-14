<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

class EventContext implements EventContextInterface
{
    private array $data = [];
    private ?PaymentContractInterface $contract = null;

    public function __construct(array $initialData = [])
    {
        $this->data = $initialData;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function getBasket(): ?object
    {
        return $this->get('basket');
    }

    public function getUser(): ?object
    {
        return $this->get('user');
    }

    public function getOrderId(): ?string
    {
        return $this->get('orderId');
    }

    public function setContract(PaymentContractInterface $contract): void
    {
        $this->contract = $contract;
    }

    public function getContract(): ?PaymentContractInterface
    {
        return $this->contract;
    }

    public function hasContract(): bool
    {
        return $this->contract !== null;
    }
}
