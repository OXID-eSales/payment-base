<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Support;

use Closure;
use DateTimeImmutable;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditHead;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditPurged;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditRecord;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditRow;
use OxidEsales\GraphQL\Admin\Audit\Infrastructure\AuditChainRepositoryInterface;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditChain;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecordFactory;
use OxidEsales\GraphQL\Admin\Authorization\Service\LastAdminGrant;

/**
 * The admin API's REAL audit recorder (its outcome mapping, its record factory, its hashing) over a chain that
 * lives in memory. The real chain is the shop's one tamper-evident table, written on a connection of its own: a
 * test could not roll its rows back and would leave them in the real audit log. The real table is exercised by
 * the admin SPA's end-to-end run, which reads the rows back through `adminAuditLog`.
 */
final class InMemoryAuditChain implements AuditChainRepositoryInterface
{
    /** @var list<AuditRow> */
    public array $rows = [];

    public static function recorder(self $chain): AuditRecorder
    {
        $container = ContainerFactory::getInstance()->getContainer();

        return new AuditRecorder(
            $chain,
            $container->get(AuditChain::class),
            $container->get(AuditRecordFactory::class),
            $container->get(LastAdminGrant::class),
        );
    }

    public function append(AuditRecord $record, Closure $seal): AuditRow
    {
        $previous = $this->head();
        $row = new AuditRow($previous->lastId + 1, $record, $previous->lastHash, $seal($previous->lastHash, $record));
        $this->rows[] = $row;

        return $row;
    }

    public function rowsAfter(int $afterId, int $limit): array
    {
        return array_slice(array_values(array_filter($this->rows, static fn (AuditRow $row): bool => $row->id > $afterId)), 0, $limit);
    }

    public function head(): AuditHead
    {
        $last = $this->rows === [] ? null : $this->rows[array_key_last($this->rows)];

        return new AuditHead($last?->id ?? 0, $last?->hash ?? str_repeat('0', 64));
    }

    public function countBefore(DateTimeImmutable $before): int
    {
        return 0;
    }

    public function purgeBefore(DateTimeImmutable $before, Closure $describe, Closure $seal): ?AuditRow
    {
        return null;
    }

    /**
     * @return array{operation: string, entityType: ?string, entityId: ?string, fields: list<string>, values: array<string, mixed>, result: string, errorCode: ?string}
     */
    public function row(int $index): array
    {
        $record = $this->rows[$index]->record;
        $details = json_decode($record->changedFieldsJson, true, flags: JSON_THROW_ON_ERROR);

        return [
            'operation' => $record->operation,
            'entityType' => $record->entity->type,
            'entityId' => $record->entity->id,
            'fields' => $details['fields'] ?? [],
            'values' => $details['values'] ?? [],
            'result' => $record->outcome->result->value,
            'errorCode' => $record->outcome->errorCode,
        ];
    }
}
