<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Stock;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use OxidEsales\PaymentBase\Stock\OxidStockRestorationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * VR-21 F3 — the one stock restoration of a fully refunded order (it mirrors core's OrderArticle::storno()):
 * once per order (claimed on the contract row), only what is not storno'd yet, `blUseStock` and
 * `blAllowNegativeStock` read from the shop. The real tables: Integration\Stock\OxidStockRestorationServiceTest.
 */
#[CoversClass(OxidStockRestorationService::class)]
final class OxidStockRestorationServiceTest extends TestCase
{
    public function testAnOrderThatIsNotThereRestoresNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(0, $this->service($connection, order: null)->restoreForFullRefund('gone'));
    }

    public function testAnOrderRestoredBeforeIsNotRestoredAgain(): void
    {
        $article = new RecordingOrderArticle('art-1', amount: 2.0, storno: 0);
        $connection = $this->connectionClaiming(claimed: 0);

        $service = $this->service($connection, $this->orderWith($article));

        self::assertSame(0, $service->restoreForFullRefund('order-1'));
        self::assertSame([], $article->stockCalls);
    }

    public function testFreshArticlesAreRestoredAndStornodWhileStornodOnesAreSkipped(): void
    {
        $fresh = new RecordingOrderArticle('art-fresh', amount: 2.0, storno: 0);
        $done = new RecordingOrderArticle('art-done', amount: 3.0, storno: 1);
        $connection = $this->connectionClaiming(claimed: 1, stornoOf: ['art-fresh']);

        $service = $this->service($connection, $this->orderWith($fresh, $done));

        self::assertSame(1, $service->restoreForFullRefund('order-1'));
        self::assertSame([[2.0, false]], $fresh->stockCalls);
        self::assertSame([], $done->stockCalls);
    }

    public function testWithStockManagementOffTheArticleIsStornodButTheStockStaysAsItIs(): void
    {
        $article = new RecordingOrderArticle('art-1', amount: 2.0, storno: 0);
        $connection = $this->connectionClaiming(claimed: 1, stornoOf: ['art-1']);

        $service = $this->service($connection, $this->orderWith($article), useStock: false);

        self::assertSame(1, $service->restoreForFullRefund('order-1'));
        self::assertSame([], $article->stockCalls);
    }

    public function testAllowNegativeStockOfTheShopIsHandedToTheStockUpdate(): void
    {
        $article = new RecordingOrderArticle('art-1', amount: 1.5, storno: 0);
        $connection = $this->connectionClaiming(claimed: 1, stornoOf: ['art-1']);

        $this->service($connection, $this->orderWith($article), allowNegativeStock: true)
            ->restoreForFullRefund('order-1');

        self::assertSame([[1.5, true]], $article->stockCalls);
    }

    public function testAFailureWhileRestoringGivesTheClaimBackSoTheRefundCanBeSettledAgain(): void
    {
        $article = new ThrowingOrderArticle('art-1');
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            }
        );

        try {
            $this->service($connection, $this->orderWith($article))->restoreForFullRefund('order-1');
            self::fail('the failure must not be swallowed');
        } catch (RuntimeException) {
        }

        self::assertCount(2, $statements);
        self::assertStringContainsString('OXSTOCKRESTOREDAT = NULL', $statements[1]);
    }

    /**
     * @param list<string> $stornoOf order articles that get their storno flag
     */
    private function connectionClaiming(int $claimed, array $stornoOf = []): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use ($claimed, $stornoOf): int {
                if (str_contains($sql, 'oe_payments_contract')) {
                    self::assertSame(['order' => 'order-1'], $params);

                    return $claimed;
                }
                self::assertSame('UPDATE oxorderarticles SET oxstorno = 1 WHERE oxid = :oxid', $sql);
                self::assertContains($params['oxid'], $stornoOf);

                return 1;
            }
        );

        return $connection;
    }

    private function orderWith(OrderArticle ...$articles): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderArticles')->willReturn($articles);

        return $order;
    }

    private function service(
        Connection $connection,
        ?Order $order,
        bool $useStock = true,
        bool $allowNegativeStock = false,
    ): OxidStockRestorationService {
        return new class ($connection, $order, $useStock, $allowNegativeStock) extends OxidStockRestorationService {
            public function __construct(
                Connection $connection,
                private readonly ?Order $order,
                private readonly bool $useStock,
                private readonly bool $allowNegativeStock,
            ) {
                parent::__construct($connection, new NullLogger());
            }

            protected function loadOrder(string $orderId): ?Order
            {
                return $this->order;
            }

            protected function shopManagesStock(): bool
            {
                return $this->useStock;
            }

            protected function shopAllowsNegativeStock(): bool
            {
                return $this->allowNegativeStock;
            }
        };
    }
}
