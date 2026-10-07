<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * `oe_payments_sessions` as the headless context store. The table was created
 * by Version20251031140200 and unused until Sprint 15 / S2: one row per
 * scope (OXSESSIONID), OXDATA as JSON, OXUSERID / OXBASKETID for
 * housekeeping, OXEXPIRES so a checkout nobody came back to does not live
 * forever. The id is derived from the scope so a save is an upsert.
 *
 * @since 3.0.0
 */
class DoctrineCheckoutContextStore implements CheckoutContextStoreInterface
{
    public const PROVIDER = 'headless';

    private const TABLE = 'oe_payments_sessions';

    private const TTL = 'P1D';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(string $scopeId): array
    {
        $json = $this->connection->fetchOne(
            'SELECT OXDATA FROM ' . self::TABLE . ' WHERE OXID = :id AND OXEXPIRES > :now',
            ['id' => $this->rowId($scopeId), 'now' => $this->now()->format('Y-m-d H:i:s')]
        );

        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $data = [];
        foreach ($decoded as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }

    public function save(string $scopeId, array $data, ?string $userId, ?string $basketId): void
    {
        $now = $this->now();
        $row = [
            'OXPROVIDER' => self::PROVIDER,
            'OXSESSIONID' => $scopeId,
            'OXUSERID' => $userId,
            'OXBASKETID' => $basketId,
            'OXDATA' => json_encode($data, JSON_THROW_ON_ERROR),
            'OXEXPIRES' => $now->add(new \DateInterval(self::TTL))->format('Y-m-d H:i:s'),
        ];

        $id = $this->rowId($scopeId);
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE OXID = :id',
            ['id' => $id]
        );

        if ($exists > 0) {
            $this->connection->update(self::TABLE, $row, ['OXID' => $id]);

            return;
        }

        $row['OXID'] = $id;
        $row['OXCREATED'] = $now->format('Y-m-d H:i:s');
        $this->connection->insert(self::TABLE, $row);
    }

    private function rowId(string $scopeId): string
    {
        return md5(self::PROVIDER . ':' . $scopeId);
    }

    /**
     * Seam for tests that need a fixed clock.
     */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
