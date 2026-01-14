<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

use DateTime;
use InvalidArgumentException;

class BasketSnapshot
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $items;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $discounts;
    private float $totalGross;
    private float $totalNet;
    private float $totalVat;
    private string $currency;
    private \DateTimeInterface $capturedAt;

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $discounts
     */
    private function __construct(
        array $items,
        array $discounts,
        float $totalGross,
        float $totalNet,
        float $totalVat,
        string $currency,
        \DateTimeInterface $capturedAt
    ) {
        $this->items = $items;
        $this->discounts = $discounts;
        $this->totalGross = $totalGross;
        $this->totalNet = $totalNet;
        $this->totalVat = $totalVat;
        $this->currency = $currency;
        $this->capturedAt = $capturedAt;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: self::extractItems($data),
            discounts: self::extractDiscounts($data),
            totalGross: self::extractFloat($data, 'totalGross'),
            totalNet: self::extractFloat($data, 'totalNet'),
            totalVat: self::extractFloat($data, 'totalVat'),
            currency: self::extractCurrency($data),
            capturedAt: self::extractCapturedAt($data)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private static function extractItems(array $data): array
    {
        if (!isset($data['items'])) {
            return [];
        }
        if (!is_array($data['items'])) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $items */
        $items = $data['items'];
        return $items;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private static function extractDiscounts(array $data): array
    {
        if (!isset($data['discounts'])) {
            return [];
        }
        if (!is_array($data['discounts'])) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $discounts */
        $discounts = $data['discounts'];
        return $discounts;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractFloat(array $data, string $key): float
    {
        if (!isset($data[$key])) {
            throw new InvalidArgumentException("Required field '{$key}' is missing");
        }
        $value = $data[$key];
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        throw new InvalidArgumentException("Field '{$key}' must be a number");
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractCurrency(array $data): string
    {
        if (!isset($data['currency'])) {
            throw new InvalidArgumentException('Required field \'currency\' is missing');
        }
        if (!is_string($data['currency'])) {
            throw new InvalidArgumentException('Field \'currency\' must be a string');
        }
        return $data['currency'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractCapturedAt(array $data): \DateTimeInterface
    {
        if (isset($data['capturedAt']) && is_string($data['capturedAt'])) {
            return new DateTime($data['capturedAt']);
        }
        return new DateTime();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDiscounts(): array
    {
        return $this->discounts;
    }

    public function getTotalGross(): float
    {
        return $this->totalGross;
    }

    public function getTotalNet(): float
    {
        return $this->totalNet;
    }

    public function getTotalVat(): float
    {
        return $this->totalVat;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCapturedAt(): \DateTimeInterface
    {
        return $this->capturedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'discounts' => $this->discounts,
            'totalGross' => $this->totalGross,
            'totalNet' => $this->totalNet,
            'totalVat' => $this->totalVat,
            'currency' => $this->currency,
            'capturedAt' => $this->capturedAt->format('Y-m-d H:i:s'),
        ];
    }
}
