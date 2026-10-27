<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Core\Registry;

/**
 * An order exists for the admin API when its row belongs to the shop of the request (`?shp=`).
 *
 * @since 1.5.0
 */
class DbalRequestShopOrders implements RequestShopOrdersInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function contains(string $orderId): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM oxorder WHERE OXID = :id AND OXSHOPID = :shop',
            ['id' => $orderId, 'shop' => $this->requestShopId()],
        );

        return $found !== false;
    }

    /**
     * Seam for tests; the shop of the request is OXID's configuration.
     */
    protected function requestShopId(): int
    {
        /** @phpstan-ignore-next-line class.notFound OXID core, present at runtime */
        return (int) Registry::getConfig()->getShopId();
    }
}
