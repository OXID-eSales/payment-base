<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Plain SQL on `oe_payments_at_risk`. Every claim is one conditional statement, so concurrent webhooks cannot
 * both win it.
 *
 * @since 1.8.0
 */
class DoctrinePaymentAtRiskRepository implements PaymentAtRiskRepositoryInterface, PaymentAtRiskQueryInterface
{
    private const TABLE = 'oe_payments_at_risk';
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function insertIfAbsent(PaymentAtRisk $flag): bool
    {
        try {
            $this->connection->insert(self::TABLE, [
                'OXID' => $flag->id,
                'OXCONTRACTID' => $flag->contractId,
                'OXORDERID' => $flag->orderId,
                'OXREASON' => $flag->reason->value,
                'OXREFERENCE' => $flag->reference,
                'OXRAISEDAT' => $flag->raisedAt->format(self::DATE_FORMAT),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function claimOwnerMail(string $contractId, AtRiskReason $reason, string $reference): bool
    {
        return $this->updateFlag($contractId, $reason, $reference, 'OXMAILEDAT = NOW()', 'OXMAILEDAT IS NULL');
    }

    public function releaseOwnerMail(string $contractId, AtRiskReason $reason, string $reference): void
    {
        $this->updateFlag($contractId, $reason, $reference, 'OXMAILEDAT = NULL', 'OXMAILEDAT IS NOT NULL');
    }

    public function acknowledge(string $contractId, AtRiskReason $reason, string $reference): bool
    {
        return $this->updateFlag($contractId, $reason, $reference, 'OXACKNOWLEDGEDAT = NOW()', 'OXACKNOWLEDGEDAT IS NULL');
    }

    public function openForContract(string $contractId): array
    {
        return $this->hydrate($this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE OXCONTRACTID = :contract AND OXACKNOWLEDGEDAT IS NULL ORDER BY OXRAISEDAT ASC, OXID ASC',
            ['contract' => $contractId]
        ));
    }

    public function openForOrder(string $orderId): array
    {
        return $this->hydrate($this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE OXORDERID = :order AND OXACKNOWLEDGEDAT IS NULL ORDER BY OXRAISEDAT ASC, OXID ASC',
            ['order' => $orderId]
        ));
    }

    public function openForOrders(int $limit): array
    {
        return $this->hydrate($this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE OXACKNOWLEDGEDAT IS NULL AND OXORDERID IS NOT NULL
             ORDER BY OXRAISEDAT DESC, OXID DESC LIMIT ' . max(1, $limit)
        ));
    }

    private function updateFlag(
        string $contractId,
        AtRiskReason $reason,
        string $reference,
        string $set,
        string $onlyWhen
    ): bool {
        return (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET ' . $set . '
             WHERE OXCONTRACTID = :contract AND OXREASON = :reason AND OXREFERENCE = :reference AND ' . $onlyWhen,
            ['contract' => $contractId, 'reason' => $reason->value, 'reference' => $reference]
        ) > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PaymentAtRisk>
     */
    private function hydrate(array $rows): array
    {
        return array_map(static fn (array $row): PaymentAtRisk => new PaymentAtRisk(
            (string) $row['OXID'],
            (string) $row['OXCONTRACTID'],
            is_string($row['OXORDERID']) ? $row['OXORDERID'] : null,
            AtRiskReason::from((string) $row['OXREASON']),
            (string) $row['OXREFERENCE'],
            new DateTimeImmutable((string) $row['OXRAISEDAT']),
        ), $rows);
    }
}
