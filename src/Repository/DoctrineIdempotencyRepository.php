<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;

/**
 * Doctrine DBAL implementation of IdempotencyRepositoryInterface.
 *
 * Sprint 42: Idempotency implementation.
 */
class DoctrineIdempotencyRepository implements IdempotencyRepositoryInterface
{
    private const TABLE = 'oe_payments_idempotency';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function save(IdempotencyRecord $record): void
    {
        $data = $this->prepareData($record);

        try {
            $exists = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE OXID = :id',
                ['id' => $record->getId()]
            );

            if ($exists > 0) {
                $this->connection->update(self::TABLE, $data, ['OXID' => $record->getId()]);
                return;
            }

            $this->connection->insert(self::TABLE, $data);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findByKey(string $key): ?IdempotencyRecord
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE OXKEY = :key';

        try {
            $data = $this->connection->fetchAssociative($sql, ['key' => $key]);

            if ($data === false) {
                return null;
            }

            return $this->hydrateRecord($data);
        } catch (Exception $e) {
            return null;
        }
    }

    public function deleteExpired(): int
    {
        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE OXEXPIRES < :now',
                ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s')]
            );
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareData(IdempotencyRecord $record): array
    {
        return [
            'OXID' => $record->getId(),
            'OXKEY' => $record->getKey(),
            'OXORDERID' => $record->getOrderId(),
            'OXOPERATION' => $record->getOperation(),
            'OXRESULT' => $record->getResult(),
            'OXSTATUS' => $record->getStatus(),
            'OXCREATED' => $record->getCreatedAt()->format('Y-m-d H:i:s'),
            'OXEXPIRES' => $record->getExpiresAt()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateRecord(array $data): IdempotencyRecord
    {
        /** @phpstan-ignore-next-line */
        $id = is_string($data['OXID']) ? $data['OXID'] : (string) ($data['OXID'] ?? '');
        /** @phpstan-ignore-next-line */
        $key = is_string($data['OXKEY']) ? $data['OXKEY'] : (string) ($data['OXKEY'] ?? '');
        /** @phpstan-ignore-next-line */
        $orderId = is_string($data['OXORDERID']) ? $data['OXORDERID'] : (string) ($data['OXORDERID'] ?? '');
        /** @phpstan-ignore-next-line */
        $operation = is_string($data['OXOPERATION']) ? $data['OXOPERATION'] : (string) ($data['OXOPERATION'] ?? '');
        /** @phpstan-ignore-next-line */
        $status = is_string($data['OXSTATUS']) ? $data['OXSTATUS'] : (string) ($data['OXSTATUS'] ?? '');
        /** @phpstan-ignore-next-line */
        $createdAt = new DateTimeImmutable(is_string($data['OXCREATED']) ? $data['OXCREATED'] : 'now');
        /** @phpstan-ignore-next-line */
        $expiresAt = new DateTimeImmutable(is_string($data['OXEXPIRES']) ? $data['OXEXPIRES'] : 'now');

        $record = new IdempotencyRecord($id, $key, $orderId, $operation, $status, $createdAt, $expiresAt);

        if (isset($data['OXRESULT']) && is_string($data['OXRESULT'])) {
            $record->setResult($data['OXRESULT']);
        }

        return $record;
    }
}
