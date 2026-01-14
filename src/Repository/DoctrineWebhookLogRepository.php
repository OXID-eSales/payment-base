<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use RuntimeException;

/**
 * @SuppressWarnings(PHPMD)
 */
class DoctrineWebhookLogRepository implements WebhookLogRepositoryInterface
{
    private const TABLE_NAME = 'osc_payment_webhooklogs';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function save(WebhookLog $log): void
    {
        try {
            $data = [
                'OXID' => $log->getId(),
                'OXEVENTID' => $log->getEventId(),
                'OXEVENTTYPE' => $log->getEventType(),
                'OXCONTRACTID' => $log->getContractId(),
                'OXSTATUS' => $log->getStatus(),
                'OXRECEIVEDAT' => $log->getReceivedAt()->format('Y-m-d H:i:s'),
                'OXERROR' => $log->getError(),
                'OXPROVIDER' => $log->getProvider(),
                'OXPAYLOAD' => $log->getPayload() !== null ? json_encode($log->getPayload()) : null,
                'OXPROCESSEDAT' => $log->getProcessedAt()?->format('Y-m-d H:i:s'),
            ];

            $exists = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE OXID = :id',
                ['id' => $log->getId()]
            );

            if ($exists > 0) {
                $this->connection->update(self::TABLE_NAME, $data, ['OXID' => $log->getId()]);
                return;
            }

            $this->connection->insert(self::TABLE_NAME, $data);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to save webhook log: ' . $e->getMessage(), 0, $e);
        }
    }

    public function existsByEventId(string $eventId): bool
    {
        try {
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE OXEVENTID = :eventId',
                ['eventId' => $eventId]
            );

            return $count > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function findByEventId(string $eventId): ?WebhookLog
    {
        try {
            $data = $this->connection->fetchAssociative(
                'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXEVENTID = :eventId',
                ['eventId' => $eventId]
            );

            if ($data === false) {
                return null;
            }

            return $this->hydrateWebhookLog($data);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateWebhookLog(array $data): WebhookLog
    {
        $log = new WebhookLog(
            $this->extractString($data, 'OXEVENTID', ''),
            new DateTimeImmutable($this->extractString($data, 'OXRECEIVEDAT', 'now')),
            $this->extractString($data, 'OXSTATUS', 'received'),
            $this->extractString($data, 'OXID', '')
        );

        $this->setOptionalWebhookProperties($log, $data);

        return $log;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setOptionalWebhookProperties(WebhookLog $log, array $data): void
    {
        if (!empty($data['OXEVENTTYPE'])) {
            $log->setEventType($this->extractString($data, 'OXEVENTTYPE'));
        }

        if (!empty($data['OXCONTRACTID'])) {
            $log->setContractId($this->extractString($data, 'OXCONTRACTID'));
        }

        if (!empty($data['OXERROR'])) {
            $log->setError($this->extractString($data, 'OXERROR'));
        }

        if (!empty($data['OXPROVIDER'])) {
            $log->setProvider($this->extractString($data, 'OXPROVIDER'));
        }

        if (!empty($data['OXPAYLOAD'])) {
            $payloadJson = $this->extractString($data, 'OXPAYLOAD');
            $payload = json_decode($payloadJson, true);
            if (is_array($payload)) {
                /** @var array<string, mixed> $payload */
                $log->setPayload($payload);
            }
        }

        if (!empty($data['OXPROCESSEDAT'])) {
            $processedAt = new DateTimeImmutable($this->extractString($data, 'OXPROCESSEDAT'));
            $log->setProcessedAt($processedAt);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @phpstan-ignore-next-line
     */
    private function extractString(array $data, string $key, string $default = ''): string
    {
        if (!isset($data[$key])) {
            return $default;
        }

        /** @phpstan-ignore-next-line */
        return is_string($data[$key]) ? $data[$key] : (string) $data[$key];
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
            throw new RuntimeException('Failed to update webhook log status: ' . $e->getMessage(), 0, $e);
        }
    }
}
