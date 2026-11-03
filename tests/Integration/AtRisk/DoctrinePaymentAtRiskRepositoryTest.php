<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\AtRisk;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\DoctrinePaymentAtRiskRepository;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRisk;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * VR-23 T1 - the flags against the real table: raising is idempotent, the owner mail is claimed once, an
 * acknowledged flag leaves the admin's open lists.
 */
#[Group('integration')]
final class DoctrinePaymentAtRiskRepositoryTest extends TestCase
{
    private Connection $connection;
    private DoctrinePaymentAtRiskRepository $repository;
    private string $contractId;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)->get();
        $this->repository = new DoctrinePaymentAtRiskRepository($this->connection);
        $this->contractId = 'it_vr23_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_at_risk WHERE OXCONTRACTID = :id', ['id' => $this->contractId]);
    }

    public function testTheSameFlagIsInsertedOnce(): void
    {
        self::assertTrue($this->repository->insertIfAbsent($this->flag(AtRiskReason::DisputeOpened, 'dp_1')));
        self::assertFalse($this->repository->insertIfAbsent($this->flag(AtRiskReason::DisputeOpened, 'dp_1')));
        self::assertTrue($this->repository->insertIfAbsent($this->flag(AtRiskReason::DisputeOpened, 'dp_2')));
        self::assertTrue($this->repository->insertIfAbsent($this->flag(AtRiskReason::CaptureDenied, '')));

        self::assertCount(3, $this->repository->openForContract($this->contractId));
    }

    public function testTheOwnerMailIsClaimedByExactlyOneCallerUntilReleased(): void
    {
        $this->repository->insertIfAbsent($this->flag(AtRiskReason::CaptureDenied, ''));

        self::assertTrue($this->repository->claimOwnerMail($this->contractId, AtRiskReason::CaptureDenied, ''));
        self::assertFalse($this->repository->claimOwnerMail($this->contractId, AtRiskReason::CaptureDenied, ''));

        $this->repository->releaseOwnerMail($this->contractId, AtRiskReason::CaptureDenied, '');

        self::assertTrue($this->repository->claimOwnerMail($this->contractId, AtRiskReason::CaptureDenied, ''));
    }

    public function testAnAcknowledgedFlagIsNoLongerOpenAndStaysBlockedFromBeingRaisedAgain(): void
    {
        $this->repository->insertIfAbsent($this->flag(AtRiskReason::DisputeOpened, 'dp_1'));

        self::assertTrue($this->repository->acknowledge($this->contractId, AtRiskReason::DisputeOpened, 'dp_1'));
        self::assertFalse($this->repository->acknowledge($this->contractId, AtRiskReason::DisputeOpened, 'dp_1'));

        self::assertSame([], $this->repository->openForContract($this->contractId));
        self::assertFalse(
            $this->repository->insertIfAbsent($this->flag(AtRiskReason::DisputeOpened, 'dp_1')),
            'a redelivered dispute event must not reopen what the merchant decided'
        );
    }

    public function testOpenOrdersListOnlyFlagsThatNameAnOrder(): void
    {
        $this->repository->insertIfAbsent($this->flag(AtRiskReason::DisputeOpened, 'dp_1', 'order_' . $this->contractId));
        $this->repository->insertIfAbsent($this->flag(AtRiskReason::CaptureDenied, '', null));

        $orders = array_filter(
            $this->repository->openForOrders(500),
            fn (PaymentAtRisk $flag): bool => $flag->contractId === $this->contractId
        );

        self::assertCount(1, $orders);
        self::assertSame('order_' . $this->contractId, array_values($orders)[0]->orderId);
    }

    private function flag(AtRiskReason $reason, string $reference, ?string $orderId = 'order_x'): PaymentAtRisk
    {
        return new PaymentAtRisk(bin2hex(random_bytes(16)), $this->contractId, $orderId, $reason, $reference, new DateTimeImmutable());
    }
}
