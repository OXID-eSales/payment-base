<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Stock;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a fully refunded order's goods back: every article not yet storno'd is restored to stock and storno'd,
 * as core's OrderArticle::storno() does for one article.
 *
 * Once per order. The claim is a timestamp on the order's contract (OXSTOCKRESTOREDAT, set by one UPDATE that
 * only matches while it is NULL), so two settlements racing - the admin action and the webhook - restore once;
 * a failure gives the claim back, so the next settlement tries again (articles already storno'd are skipped).
 * The module owns oe_payments_contract; oxorder, a core table, gets no column.
 *
 * Do NOT call $order->recalculateOrder() here: it rebuilds the totals from a basket without storno'd articles,
 * which is empty now, and would overwrite the order's totals with zeros. They stay intact for accounting.
 *
 * Framework seams (protected, overridden in tests): loadOrder() needs the OXID factory, the two shop settings
 * need the shop config.
 *
 * @since 1.7.0
 */
class OxidStockRestorationService implements StockRestorationServiceInterface
{
    private const CONTRACTS = 'oe_payments_contract';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function restoreForFullRefund(string $orderId): int
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            $this->logger->warning('[OxidStockRestorationService] order not found', ['orderId' => $orderId]);

            return 0;
        }
        if (!$this->claim($orderId)) {
            $this->logger->info('[OxidStockRestorationService] nothing to restore: restored before, or no contract', [
                'orderId' => $orderId,
            ]);

            return 0;
        }

        try {
            $restored = $this->restoreArticles($order);
        } catch (Throwable $failure) {
            $this->releaseClaim($orderId);
            throw $failure;
        }

        $this->logger->info('[OxidStockRestorationService] stock restored', [
            'orderId' => $orderId,
            'articlesProcessed' => $restored,
        ]);

        return $restored;
    }

    protected function loadOrder(string $orderId): ?Order
    {
        /** @var Order $order */
        $order = oxNew(Order::class);

        return $order->load($orderId) ? $order : null;
    }

    protected function shopManagesStock(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blUseStock');
    }

    protected function shopAllowsNegativeStock(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blAllowNegativeStock');
    }

    private function claim(string $orderId): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . self::CONTRACTS . ' SET OXSTOCKRESTOREDAT = NOW() WHERE OXORDERID = :order AND OXSTOCKRESTOREDAT IS NULL',
            ['order' => $orderId]
        ) > 0;
    }

    private function releaseClaim(string $orderId): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . self::CONTRACTS . ' SET OXSTOCKRESTOREDAT = NULL WHERE OXORDERID = :order',
            ['order' => $orderId]
        );
    }

    private function restoreArticles(Order $order): int
    {
        $restored = 0;
        /** @var OrderArticle $orderArticle */
        foreach ($order->getOrderArticles() as $orderArticle) {
            $restored += $this->restoreArticle($orderArticle) ? 1 : 0;
        }

        return $restored;
    }

    private function restoreArticle(OrderArticle $orderArticle): bool
    {
        if ((int) $orderArticle->oxorderarticles__oxstorno->value === 1) {
            return false;
        }

        $amount = (float) $orderArticle->oxorderarticles__oxamount->value;
        if ($amount > 0 && $this->shopManagesStock()) {
            $orderArticle->updateArticleStock($amount, $this->shopAllowsNegativeStock());
        }

        $this->connection->executeStatement(
            'UPDATE oxorderarticles SET oxstorno = 1 WHERE oxid = :oxid',
            ['oxid' => $orderArticle->getId()]
        );

        return true;
    }
}
