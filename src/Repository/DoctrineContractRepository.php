<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use ReflectionClass;
use ReflectionException;

/**
 * Doctrine DBAL implementation of ContractRepositoryInterface
 *
 * @SuppressWarnings(PHPMD)
 */
class DoctrineContractRepository implements ContractRepositoryInterface
{
    private const TABLE_CONTRACTS = 'osc_payment_contract';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function save(PaymentContractInterface $contract): void
    {
        // Repository does not manage transactions - this is the responsibility of the application layer
        // This follows SOLID principles (Single Responsibility) and allows proper transaction management
        // at the use case/service layer where business logic resides
        try {
            $this->saveContract($contract);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findById(string $id): ?PaymentContractInterface
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . ' WHERE OXID = :id';

        try {
            $data = $this->connection->fetchAssociative($sql, ['id' => $id]);

            if ($data === false) {
                return null;
            }

            return $this->hydrateContract($data);
        } catch (Exception $e) {
            return null;
        }
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . ' WHERE OXPROVIDERORDERID = :providerOrderId';

        try {
            $data = $this->connection->fetchAssociative($sql, ['providerOrderId' => $providerOrderId]);

            if ($data === false) {
                return null;
            }

            return $this->hydrateContract($data);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findByUserId(string $userId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . ' WHERE OXUSERID = :userId ORDER BY OXCREATED DESC';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, ['userId' => $userId]);

            return array_map(fn($row) => $this->hydrateContract($row), $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    public function findActiveByUserId(string $userId): ?PaymentContractInterface
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . '
                WHERE OXUSERID = :userId
                AND OXSTATE IN (:states)
                ORDER BY OXCREATED DESC
                LIMIT 1';

        try {
            $data = $this->connection->fetchAssociative(
                $sql,
                [
                    'userId' => $userId,
                    'states' => ['draft', 'pending', 'ready_to_commit', 'committed']
                ],
                [
                    'states' => Connection::PARAM_STR_ARRAY
                ]
            );

            if ($data === false) {
                return null;
            }

            return $this->hydrateContract($data);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findExpired(?DateTimeInterface $before = null): array
    {
        $before = $before ?? new DateTime();

        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . '
                WHERE OXEXPIRESAT < :before
                AND OXSTATE NOT IN (:states)
                ORDER BY OXEXPIRESAT ASC';

        try {
            $rows = $this->connection->fetchAllAssociative(
                $sql,
                [
                    'before' => $before->format('Y-m-d H:i:s'),
                    'states' => ['fulfilled', 'cancelled', 'expired', 'failed']
                ],
                [
                    'states' => Connection::PARAM_STR_ARRAY
                ]
            );

            return array_map(fn($row) => $this->hydrateContract($row), $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @throws Exception
     */
    private function saveContract(PaymentContractInterface $contract): void
    {
        $data = $this->prepareContractData($contract);

        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE_CONTRACTS . ' WHERE OXID = :id',
            ['id' => $contract->getId()]
        );

        if ($exists > 0) {
            $this->connection->update(self::TABLE_CONTRACTS, $data, ['OXID' => $contract->getId()]);
            return;
        }

        $this->connection->insert(self::TABLE_CONTRACTS, $data);
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    private function prepareContractData(PaymentContractInterface $contract): array
    {
        $contractArray = $contract->toArray();

        $createdAt = $contractArray['createdAt'] ?? 'now';
        $updatedAt = $contractArray['updatedAt'] ?? 'now';

        return [
            'OXID' => $contract->getId(),
            'OXSHOPID' => $contractArray['shopId'] ?? 1,
            'OXUSERID' => $contractArray['userId'] ?? '',
            'OXORDERID' => $contractArray['orderId'] ?? null,
            'OXSTATE' => $contract->getStateValue(),
            'OXSTATEREASON' => $contractArray['stateReason'] ?? null,
            'OXBASKETDATA' => json_encode($contractArray['basketSnapshot'] ?? []),
            'OXTERMS' => isset($contractArray['terms']) ? json_encode($contractArray['terms']) : null,
            'OXMETADATA' => isset($contractArray['metadata']) ? json_encode($contractArray['metadata']) : null,
            'OXCONDITIONS' => json_encode($contractArray['conditions'] ?? []),
            'OXPROVIDER' => $contractArray['provider'] ?? null,
            'OXPROVIDERORDERID' => $contractArray['providerOrderId'] ?? null,
            'OXPROVIDERDATA' => isset($contractArray['providerData']) ? json_encode($contractArray['providerData']) : null,
            'OXCREATED' => $this->formatDateTime($createdAt),
            'OXUPDATED' => $this->formatDateTime($updatedAt),
            'OXCOMMITTEDAT' => isset($contractArray['committedAt']) ? $this->formatDateTime($contractArray['committedAt']) : null,
            'OXFULFILLEDAT' => isset($contractArray['fulfilledAt']) ? $this->formatDateTime($contractArray['fulfilledAt']) : null,
            'OXEXPIRESAT' => isset($contractArray['expiresAt']) ? $this->formatDateTime($contractArray['expiresAt']) : null,
            'OXCAPTUREDAMOUNT' => $contractArray['capturedAmount'] ?? null,
            'OXREFUNDEDAMOUNT' => $contractArray['refundedAmount'] ?? null,
            'OXCAPTUREDAT' => isset($contractArray['capturedAt']) ? $this->formatDateTime($contractArray['capturedAt']) : null,
            'OXREFUNDEDAT' => isset($contractArray['refundedAt']) ? $this->formatDateTime($contractArray['refundedAt']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws Exception
     * @SuppressWarnings(PHPMD)
     */
    private function hydrateContract(array $data): PaymentContractInterface
    {
        $basketSnapshot = $this->hydrateContractBasketSnapshot($data);
        $conditions = $this->hydrateContractConditions($data);
        $requiredFields = $this->extractContractRequiredFields($data);

        $contract = new PaymentContract(
            $requiredFields['shopId'],
            $requiredFields['userId'],
            $basketSnapshot,
            $requiredFields['contractId']
        );

        $this->setContractPrivateProperties($contract, $data, $conditions);

        return $contract;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateContractBasketSnapshot(array $data): BasketSnapshot
    {
        $basketDataString = is_string($data['OXBASKETDATA']) ? $data['OXBASKETDATA'] : '[]';
        /** @var array<string, mixed> $basketData */
        $basketData = json_decode($basketDataString, true) ?: [];
        return BasketSnapshot::fromArray($basketData);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, ContractCondition>
     */
    private function hydrateContractConditions(array $data): array
    {
        $conditionsDataString = is_string($data['OXCONDITIONS']) ? $data['OXCONDITIONS'] : '[]';
        /** @var array<int, array<string, mixed>> $conditionsData */
        $conditionsData = json_decode($conditionsDataString, true) ?: [];
        return array_map(
            fn(array $condData): ContractCondition => ContractCondition::fromArray($condData),
            $conditionsData
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{shopId: int, userId: string, contractId: string}
     */
    private function extractContractRequiredFields(array $data): array
    {
        /** @phpstan-ignore-next-line */
        $shopId = is_int($data['OXSHOPID']) ? $data['OXSHOPID'] : (int) ($data['OXSHOPID'] ?? 0);
        /** @phpstan-ignore-next-line */
        $userId = is_string($data['OXUSERID']) ? $data['OXUSERID'] : (string) ($data['OXUSERID'] ?? '');
        /** @phpstan-ignore-next-line */
        $contractId = is_string($data['OXID']) ? $data['OXID'] : (string) ($data['OXID'] ?? '');

        return [
            'shopId' => $shopId,
            'userId' => $userId,
            'contractId' => $contractId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, ContractCondition> $conditions
     */
    private function setContractPrivateProperties(
        PaymentContract $contract,
        array $data,
        array $conditions
    ): void {
        /** @phpstan-ignore-next-line */
        $stateValue = is_string($data['OXSTATE']) ? $data['OXSTATE'] : (string) ($data['OXSTATE'] ?? 'draft');
        $this->setPrivateProperty($contract, 'state', ContractState::fromValue($stateValue));
        $this->setPrivateProperty($contract, 'orderId', $data['OXORDERID']);
        $this->setPrivateProperty($contract, 'provider', $data['OXPROVIDER']);
        $this->setPrivateProperty($contract, 'providerOrderId', $data['OXPROVIDERORDERID']);
        $this->setPrivateProperty($contract, 'expiresAt', $this->parseDateTime($data['OXEXPIRESAT']));
        $this->setPrivateProperty($contract, 'createdAt', $this->parseDateTime($data['OXCREATED']));
        $this->setPrivateProperty($contract, 'updatedAt', $this->parseDateTime($data['OXUPDATED']));
        $this->setPrivateProperty($contract, 'committedAt', $this->parseDateTime($data['OXCOMMITTEDAT']));
        $this->setPrivateProperty($contract, 'fulfilledAt', $this->parseDateTime($data['OXFULFILLEDAT']));
        $this->setPrivateProperty($contract, 'conditions', $conditions);

        // Restore metadata from database
        $metadata = $this->hydrateContractMetadata($data);
        $this->setPrivateProperty($contract, 'metadata', $metadata);

        // Sprint 8: Capture/Refund tracking fields
        $this->setPrivateProperty($contract, 'capturedAmount', $this->parseOptionalFloat($data['OXCAPTUREDAMOUNT'] ?? null));
        $this->setPrivateProperty($contract, 'refundedAmount', $this->parseOptionalFloat($data['OXREFUNDEDAMOUNT'] ?? null));
        $this->setPrivateProperty($contract, 'capturedAt', $this->parseDateTime($data['OXCAPTUREDAT'] ?? null));
        $this->setPrivateProperty($contract, 'refundedAt', $this->parseDateTime($data['OXREFUNDEDAT'] ?? null));
    }

    /**
     * Hydrate metadata from database JSON.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function hydrateContractMetadata(array $data): array
    {
        $metadataString = is_string($data['OXMETADATA']) ? $data['OXMETADATA'] : null;
        if ($metadataString === null || $metadataString === '') {
            return [];
        }

        /** @var array<string, mixed>|null $metadata */
        $metadata = json_decode($metadataString, true);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Set a private property value using reflection.
     */
    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        try {
            $reflection = new ReflectionClass($object);
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue($object, $value);
        } catch (ReflectionException $e) {
            // Property doesn't exist, skip
        }
    }

    /**
     * Format a DateTime value for database storage
     */
    private function formatDateTime(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $stringValue = is_string($value) ? $value : 'now';
        return (new DateTime($stringValue))->format('Y-m-d H:i:s');
    }

    /**
     * Parse a DateTime from database value
     */
    private function parseDateTime(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        /** @phpstan-ignore-next-line */
        $stringValue = is_string($value) ? $value : (string) $value;
        return new DateTime($stringValue);
    }

    /**
     * Parse an optional float from database value
     */
    private function parseOptionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value) || is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }
}
