<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\Shop;
use RuntimeException;

/**
 * The shop's own settings (Master Settings > Basic Settings): the order owner address core's own owner mails
 * go to, and the same owner address as the sender, exactly as `Email::sendOrderEMailToOwner()` does.
 *
 * @since 1.8.0
 */
class OxidShopOwnerAddress implements ShopOwnerAddressInterface
{
    public function forShop(int $shopId): ShopOwnerAddress
    {
        $shop = $this->loadShop($shopId);
        if ($shop === null) {
            throw new RuntimeException('Cannot mail the owner of shop ' . $shopId . ': no such shop.');
        }

        $owner = (string) $shop->getFieldData('oxowneremail');

        return new ShopOwnerAddress($owner, (string) $shop->getFieldData('oxname'), $owner);
    }

    protected function loadShop(int $shopId): ?Shop
    {
        $shop = oxNew(Shop::class);

        return $shop->load((string) $shopId) ? $shop : null;
    }
}
