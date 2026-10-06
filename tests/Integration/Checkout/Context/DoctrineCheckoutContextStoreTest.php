<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout\Context;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Checkout\Context\DoctrineCheckoutContextStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 15 / S2 — the headless checkout context lives in `oe_payments_sessions`
 * (created by Version20251031140200, unused until now): one row per scope,
 * OXDATA as JSON, OXUSERID / OXBASKETID for housekeeping, OXEXPIRES so a
 * forgotten checkout does not live forever.
 */
#[Group('integration')]
final class DoctrineCheckoutContextStoreTest extends IntegrationTestCase
{
    private Connection $connection;

    private DoctrineCheckoutContextStore $store;

    public function setUp(): void
    {
        parent::setUp();
        $this->connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        $this->store = new DoctrineCheckoutContextStore($this->connection);
    }

    public function testAnUnknownScopeIsEmpty(): void
    {
        self::assertSame([], $this->store->load('nope-' . uniqid()));
    }

    public function testSavingThenLoadingRoundTripsTheDataAndRecordsTheOwner(): void
    {
        $scope = 'it_scope_' . uniqid();

        $this->store->save($scope, ['oepb_open_checkout_contract_id' => 'c-1', 'n' => 2], 'user-1', 'ub-1');

        self::assertSame(['oepb_open_checkout_contract_id' => 'c-1', 'n' => 2], $this->store->load($scope));
        $row = $this->connection->fetchAssociative(
            'SELECT OXPROVIDER, OXUSERID, OXBASKETID, OXEXPIRES FROM oe_payments_sessions WHERE OXSESSIONID = :s',
            ['s' => $scope]
        );
        self::assertIsArray($row);
        self::assertSame(DoctrineCheckoutContextStore::PROVIDER, $row['OXPROVIDER']);
        self::assertSame('user-1', $row['OXUSERID']);
        self::assertSame('ub-1', $row['OXBASKETID']);
        self::assertGreaterThan(new DateTimeImmutable('+23 hours'), new DateTimeImmutable($row['OXEXPIRES']));
    }

    public function testSavingAgainUpdatesTheSameRow(): void
    {
        $scope = 'it_scope_' . uniqid();
        $this->store->save($scope, ['a' => 1], 'user-1', null);

        $this->store->save($scope, ['a' => 2, 'b' => true], 'user-1', null);

        self::assertSame(['a' => 2, 'b' => true], $this->store->load($scope));
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM oe_payments_sessions WHERE OXSESSIONID = :s',
                ['s' => $scope]
            )
        );
    }

    public function testAnExpiredRowReadsAsEmpty(): void
    {
        $scope = 'it_scope_' . uniqid();
        $this->store->save($scope, ['a' => 1], null, null);
        $this->connection->executeStatement(
            'UPDATE oe_payments_sessions SET OXEXPIRES = :past WHERE OXSESSIONID = :s',
            ['past' => '2000-01-01 00:00:00', 's' => $scope]
        );

        self::assertSame([], $this->store->load($scope));
    }
}
