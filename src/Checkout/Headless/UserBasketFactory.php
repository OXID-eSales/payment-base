<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use InvalidArgumentException;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Core\Field;

/**
 * @since 3.0.0
 */
class UserBasketFactory implements UserBasketFactoryInterface
{
    public const TITLE = 'agent-checkout';

    public function create(string $userId, array $items, string $paymentId): string
    {
        $lines = $this->normaliseItems($items);

        $row = $this->newUserBasket();
        $row->setId('hb' . substr(md5(uniqid('', true)), 0, 30));
        $row->oxuserbaskets__oxuserid = new Field($userId, Field::T_RAW);
        $row->oxuserbaskets__oxtitle = new Field(self::TITLE, Field::T_RAW);
        $row->oxuserbaskets__oxpublic = new Field(0, Field::T_RAW);
        // The storefront's column; ignored by the shop where the storefront
        // is not installed (the caller passes the payment explicitly then).
        $row->oxuserbaskets__oegql_paymentid = new Field($paymentId, Field::T_RAW);
        $row->save();

        foreach ($lines as [$productId, $amount]) {
            $row->addItemToBasket($productId, $amount);
        }

        return (string) $row->getId();
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{0: string, 1: float}>
     */
    private function normaliseItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('At least one item is required');
        }

        $lines = [];
        foreach ($items as $item) {
            $productId = $item['id'] ?? $item['productId'] ?? null;
            $amount = $item['quantity'] ?? $item['amount'] ?? null;
            if (!is_string($productId) || $productId === '' || !is_numeric($amount) || (float) $amount <= 0) {
                throw new InvalidArgumentException('Each item needs a product id and a quantity greater than zero');
            }
            $lines[] = [$productId, (float) $amount];
        }

        return $lines;
    }

    /**
     * Seam: the one oxNew() for the row.
     */
    protected function newUserBasket(): UserBasket
    {
        /** @var UserBasket $row */
        $row = oxNew(UserBasket::class);

        return $row;
    }
}
