<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;

/**
 * Doctrine DBAL implementation of IdempotencyRepositoryInterface.
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

        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE OXID = :id',
            ['id' => $record->getId()]
        );

        if ($exists > 0) {
            $this->connection->update(self::TABLE, $data, ['OXID' => $record->getId()]);
            return;
        }

        $this->connection->insert(self::TABLE, $data);
    }

    public function findByKey(string $key): ?IdempotencyRecord
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE OXKEY = :key';

        // VR-22: a database error is not "no record" - that read as a fresh claim.
        $data = $this->connection->fetchAssociative($sql, ['key' => $key]);

        return $data === false ? null : $this->hydrateRecord($data);
    }

    public function claim(IdempotencyRecord $record, DateTimeImmutable $now): bool
    {
        $data = $this->prepareData($record);
        try {
            $this->connection->insert(self::TABLE, $data);

            return true;
        } catch (UniqueConstraintViolationException) {
            $columns = array_keys(array_diff_key($data, ['OXID' => true]));

            return (int) $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET '
                . implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, $columns))
                . ' WHERE OXID = :id AND OXEXPIRES < :now',
                array_diff_key($data, ['OXID' => true])
                    + ['id' => $record->getId(), 'now' => $now->format('Y-m-d H:i:s')]
            ) > 0;
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
