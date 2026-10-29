<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;
use OxidEsales\PaymentBase\Adapter\OrderRestockInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S4 (G9) — an ended order gives its stock back, once.
 *
 * Plain TestCase with prefixed rows: core's model writes run on their own
 * connection and wait on an open test transaction. Own process: the container
 * it builds must not be the one a later IntegrationTestCase gets.
 */
#[RunTestsInSeparateProcesses]
final class OxidOrderRestockTest extends TestCase
{
    private const SHOP_ID = 1;

    private Connection $connection;
    /** @var list<array{0: string, 1: string, 2: string}> table/column/id to delete */
    private array $cleanup = [];
    /** @var list<Article> */
    private array $articles = [];

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)->get();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as [$table, $column, $id]) {
            $this->connection->executeStatement("DELETE FROM {$table} WHERE {$column} = :id", ['id' => $id]);
        }
        foreach ($this->articles as $article) {
            $article->delete();
        }
    }

    public function testRestock_ReturnsTheAmountOfEachLineOnce(): void
    {
        $articleId = $this->insertArticle(stock: 10);
        $orderId = $this->insertNotFinishedOrderWith($articleId, amount: 2);
        $restock = ContainerFactory::getInstance()->getContainer()->get(OrderRestockInterface::class);

        $restock->restock($orderId);
        $restock->restock($orderId);

        self::assertSame(12.0, $this->stockOf($articleId));
        self::assertSame('1', $this->lineStorno($orderId));
    }

    public function testDeleteNotFinishedOrder_StornosAndReturnsTheStock(): void
    {
        $articleId = $this->insertArticle(stock: 10);
        $orderId = $this->insertNotFinishedOrderWith($articleId, amount: 2);
        $orders = ContainerFactory::getInstance()->getContainer()->get(ShopOrderServiceInterface::class);

        self::assertTrue($orders->deleteNotFinishedOrder($orderId));

        self::assertSame(12.0, $this->stockOf($articleId), 'G9: the stock stays taken');
        self::assertSame('1', (string) $this->connection->fetchOne(
            'SELECT OXSTORNO FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        ));
    }

    public function testLinkedOrderEnding_UnpaidOrder_StornosReturnsTheStockAndKeepsTheStatus(): void
    {
        $articleId = $this->insertArticle(stock: 10);
        $orderId = $this->insertNotFinishedOrderWith($articleId, amount: 2);
        $ending = ContainerFactory::getInstance()->getContainer()->get(LinkedOrderEndingInterface::class);

        $ending->end($orderId, LinkedOrderEndingInterface::STATUS_FAILED);

        self::assertSame(12.0, $this->stockOf($articleId));
        self::assertSame(['1', 'FAILED'], array_map('strval', (array) $this->connection->fetchNumeric(
            'SELECT OXSTORNO, OXTRANSSTATUS FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        )));
    }

    private function stockOf(string $articleId): float
    {
        return (float) $this->connection->fetchOne('SELECT OXSTOCK FROM oxarticles WHERE OXID = :id', ['id' => $articleId]);
    }

    private function lineStorno(string $orderId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT OXSTORNO FROM oxorderarticles WHERE OXORDERID = :id',
            ['id' => $orderId]
        );
    }

    /**
     * Saved through the shop model, so core loads it back on CE and EE (shop
     * mapping) and on a shop without demo data; deleted the same way.
     */
    private function insertArticle(int $stock): string
    {
        $article = oxNew(Article::class);
        $article->setId(substr('opc175_art_' . bin2hex(random_bytes(8)), 0, 32));
        $article->oxarticles__oxshopid = new Field(Registry::getConfig()->getShopId(), Field::T_RAW);
        $article->oxarticles__oxactive = new Field(1, Field::T_RAW);
        $article->oxarticles__oxartnum = new Field('OPC175-RESTOCK', Field::T_RAW);
        $article->oxarticles__oxtitle = new Field('OPC-175 restock', Field::T_RAW);
        $article->oxarticles__oxprice = new Field(21.0, Field::T_RAW);
        $article->oxarticles__oxstock = new Field($stock, Field::T_RAW);
        $article->oxarticles__oxstockflag = new Field(1, Field::T_RAW);
        $article->save();
        $this->articles[] = $article;

        return (string) $article->getId();
    }

    private function insertNotFinishedOrderWith(string $articleId, int $amount): string
    {
        $id = substr('opc175_ord_' . bin2hex(random_bytes(8)), 0, 32);
        $this->cleanup[] = ['oxorder', 'OXID', $id];
        $this->cleanup[] = ['oxorderarticles', 'OXORDERID', $id];
        $this->connection->insert('oxorder', [
            'OXID' => $id,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => 'opc175_user',
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(1000000, 9999999),
            'OXTRANSSTATUS' => 'NOT_FINISHED',
            'OXBILLEMAIL' => 'opc175@example.com',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXPAYMENTTYPE' => 'oxidcashondel',
            'OXTOTALBRUTSUM' => 42.00,
            'OXTOTALORDERSUM' => 42.00,
            'OXCURRENCY' => 'EUR',
            'OXCURRATE' => 1,
            'OXFOLDER' => 'ORDERFOLDER_NEW',
        ]);
        $this->connection->insert('oxorderarticles', [
            'OXID' => substr('opc175_oa_' . bin2hex(random_bytes(8)), 0, 32),
            'OXORDERID' => $id,
            'OXARTID' => $articleId,
            'OXARTNUM' => $articleId,
            'OXTITLE' => 'OPC-175 restock',
            'OXAMOUNT' => $amount,
            'OXBRUTPRICE' => 42.0,
            'OXSTORNO' => 0,
        ]);

        return $id;
    }
}
