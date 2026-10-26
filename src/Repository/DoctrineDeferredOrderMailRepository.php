<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\StoredOrderMail;
use RuntimeException;

/**
 * STRP-176 — `oe_payments_order_mail`, one row per deferred order mail.
 *
 * @since 1.5.0
 */
class DoctrineDeferredOrderMailRepository implements DeferredOrderMailRepositoryInterface
{
    private const TABLE_NAME = 'oe_payments_order_mail';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(string $orderId, int $shopId, array $mails): void
    {
        try {
            foreach ($mails as $mail) {
                $this->connection->insert(self::TABLE_NAME, $this->toRow($orderId, $shopId, $mail));
            }
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to store the deferred order mail of order ' . $orderId . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function deleteByOrderId(string $orderId): int
    {
        try {
            return (int) $this->connection->delete(self::TABLE_NAME, ['OXORDERID' => $orderId]);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to delete the deferred order mails of order ' . $orderId . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function findUnsentByOrderId(string $orderId): array
    {
        $sql = 'SELECT m.* FROM ' . self::TABLE_NAME . ' m
                JOIN oxorder o ON o.OXID = m.OXORDERID
                WHERE m.OXORDERID = :orderId AND m.OXCLAIMEDAT IS NULL AND o.OXSTORNO = 0
                ORDER BY m.OXTIMESTAMP, m.OXKIND';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, ['orderId' => $orderId]);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to read the deferred order mails of order ' . $orderId . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        return array_map(fn (array $row): StoredOrderMail => $this->fromRow($row), $rows);
    }

    public function claim(string $mailId): bool
    {
        return $this->write(
            fn (): int => (int) $this->connection->executeStatement(
                'UPDATE ' . self::TABLE_NAME . ' SET OXCLAIMEDAT = NOW() WHERE OXID = :id AND OXCLAIMEDAT IS NULL',
                ['id' => $mailId]
            ),
            'claim',
            $mailId
        ) === 1;
    }

    public function release(string $mailId): void
    {
        $this->write(
            fn (): int => (int) $this->connection->update(self::TABLE_NAME, ['OXCLAIMEDAT' => null], ['OXID' => $mailId]),
            'release',
            $mailId
        );
    }

    public function delete(string $mailId): void
    {
        $this->write(
            fn (): int => (int) $this->connection->delete(self::TABLE_NAME, ['OXID' => $mailId]),
            'delete',
            $mailId
        );
    }

    /**
     * @param callable(): int $statement
     */
    private function write(callable $statement, string $action, string $mailId): int
    {
        try {
            return $statement();
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to ' . $action . ' deferred order mail ' . $mailId . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): StoredOrderMail
    {
        return new StoredOrderMail(
            id: (string) $row['OXID'],
            shopId: (int) $row['OXSHOPID'],
            mail: new DeferredOrderMail(
                kind: (string) $row['OXKIND'],
                from: (string) $row['OXFROM'],
                fromName: (string) $row['OXFROMNAME'],
                recipients: $this->addressList($row['OXRECIPIENTS']),
                replyTo: $this->addressList($row['OXREPLYTO']),
                cc: $this->addressList($row['OXCC']),
                bcc: $this->addressList($row['OXBCC']),
                subject: (string) $row['OXSUBJECT'],
                body: (string) $row['OXBODY'],
                altBody: (string) $row['OXALTBODY'],
            ),
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function addressList(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $pairs = [];
        foreach ($decoded as $pair) {
            if (is_array($pair) && isset($pair[0])) {
                $pairs[] = [(string) $pair[0], (string) ($pair[1] ?? '')];
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, int|string>
     */
    private function toRow(string $orderId, int $shopId, DeferredOrderMail $mail): array
    {
        return [
            'OXID' => bin2hex(random_bytes(16)),
            'OXORDERID' => $orderId,
            'OXSHOPID' => $shopId,
            'OXKIND' => $mail->kind,
            'OXFROM' => $mail->from,
            'OXFROMNAME' => $mail->fromName,
            'OXRECIPIENTS' => json_encode($mail->recipients, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'OXREPLYTO' => json_encode($mail->replyTo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'OXCC' => json_encode($mail->cc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'OXBCC' => json_encode($mail->bcc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'OXSUBJECT' => $mail->subject,
            'OXBODY' => $mail->body,
            'OXALTBODY' => $mail->altBody,
        ];
    }
}
