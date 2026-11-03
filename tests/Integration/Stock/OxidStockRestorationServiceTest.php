<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Stock;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Stock\OxidStockRestorationService;
use OxidEsales\PaymentBase\Tests\Unit\Stock\RecordingOrderArticle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * VR-21 F3 - the claim on the contract row: the first call restores, the second finds the claim set.
 * Which articles are restored is pinned in the unit suite.
 */
#[Group('integration')]
final class OxidStockRestorationServiceTest extends TestCase
{
    private Connection $connection;

    private string $contractId;

    private string $orderId;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)->get();
        $this->contractId = 'it_vr21st_' . bin2hex(random_bytes(8));
        $this->orderId = 'it_vr21st_order_' . bin2hex(random_bytes(6));

        $contract = new PaymentContract(1, 'it_vr21_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [],
            'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), $this->contractId);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($this->orderId);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($this->orderId);
        (new DoctrineContractRepository($this->connection, new NullLogger(), new ThrowableSummary([])))->save($contract);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = :id', ['id' => $this->contractId]);
    }

    public function testTheSecondRestorationOfAnOrderFindsTheClaimSetAndRestoresNothing(): void
    {
        $service = $this->service();

        self::assertSame(1, $service->restoreForFullRefund($this->orderId));
        self::assertNotNull($this->claimedAt());
        self::assertSame(0, $service->restoreForFullRefund($this->orderId));
    }

    public function testAnOrderWithoutAContractRestoresNothing(): void
    {
        self::assertSame(0, $this->service()->restoreForFullRefund('it_vr21st_no_contract'));
    }

    private function claimedAt(): mixed
    {
        return $this->connection->fetchOne(
            'SELECT OXSTOCKRESTOREDAT FROM oe_payments_contract WHERE OXID = :id',
            ['id' => $this->contractId]
        ) ?: null;
    }

    /** One fresh article stands in for the order's lines; the shop's order model is not loaded. */
    private function service(): OxidStockRestorationService
    {
        return new class ($this->connection) extends OxidStockRestorationService {
            public function __construct(Connection $connection)
            {
                parent::__construct($connection, new NullLogger());
            }

            protected function loadOrder(string $orderId): ?Order
            {
                $order = new class extends Order {
                    public function __construct()
                    {
                    }

                    public function getOrderArticles($blExcludeCanceled = false)
                    {
                        return [new RecordingOrderArticle('it_vr21st_art', 1.0, 0)];
                    }
                };

                return $order;
            }

            protected function shopManagesStock(): bool
            {
                return false;
            }
        };
    }
}
