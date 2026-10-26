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
