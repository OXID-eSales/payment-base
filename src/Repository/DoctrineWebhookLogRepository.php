<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use RuntimeException;

/**
 * @SuppressWarnings(PHPMD)
 */
class DoctrineWebhookLogRepository implements WebhookLogRepositoryInterface
{
    private const TABLE_NAME = 'oe_payments_webhooklogs';
    private const STATUS_CLAIMED = 'claimed';
    private const STATUS_FAILED = 'failed';

    /**
     * Longer than any webhook request can run (PHP's execution limit included): a claim this old belongs
     * to a process that died.
     */
    private const STALE_CLAIM_SECONDS = 600;

    public function __construct(
        private readonly Connection $connection,
        private readonly ThrowableSummaryInterface $throwableSummary
    ) {
    }

    /**
     * @inheritDoc
     *
     * First delivery: INSERT on UNIQUE(OXEVENTID). A later delivery of the same event hits the key; it
     * may still claim when the earlier attempt ended `failed` - the PSP was answered non-2xx for exactly
     * that reason and is now retrying - or when it is still `claimed` after STALE_CLAIM_SECONDS: a fatal
     * error or a timeout never reached the processor's catch, and no request is working on it any more
     * (VR-19). The re-claim is one UPDATE guarded by status and age, so two concurrent retries cannot both
     * win. Processed rows and fresh claims stay exclusive.
     */
    public function claimEvent(string $eventId, string $provider, string $eventType): bool
    {
        try {
            $this->connection->insert(self::TABLE_NAME, [
                'OXID' => bin2hex(random_bytes(16)),
                'OXEVENTID' => $eventId,
                'OXPROVIDER' => $provider,
                'OXEVENTTYPE' => $eventType,
                'OXSTATUS' => self::STATUS_CLAIMED,
                'OXRECEIVEDAT' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return $this->reclaim($eventId);
        }
    }

    private function reclaim(string $eventId): bool
    {
        $now = new \DateTimeImmutable();
        $affected = (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE_NAME . '
             SET OXSTATUS = :claimed, OXRECEIVEDAT = :now, OXPROCESSEDAT = NULL, OXERROR = NULL
             WHERE OXEVENTID = :eventId
               AND (OXSTATUS = :failed OR (OXSTATUS = :claimed AND OXRECEIVEDAT < :staleBefore))',
            [
                'claimed' => self::STATUS_CLAIMED,
                'failed' => self::STATUS_FAILED,
                'now' => $now->format('Y-m-d H:i:s'),
                'staleBefore' => $now->modify('-' . self::STALE_CLAIM_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
                'eventId' => $eventId,
            ]
        );

        return $affected > 0;
    }

    public function updateStatus(
        string $eventId,
        string $status,
        ?string $error = null,
        ?string $contractId = null
    ): void {
        try {
            $data = [
                'OXSTATUS' => $status,
                'OXPROCESSEDAT' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];

            if ($error !== null) {
                $data['OXERROR'] = $error;
            }

            if ($contractId !== null) {
                $data['OXCONTRACTID'] = $contractId;
            }

            $this->connection->update(self::TABLE_NAME, $data, ['OXEVENTID' => $eventId]);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update webhook log status: ' . $this->throwableSummary->toLine($e), 0, $e);
        }
    }
}
