<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Checkout\Headless\GuestBasketClaimInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUser;

/**
 * An anonymous token's basket has no owner (`oxuserbaskets.OXUSERID` is
 * empty: spike of 2026-10-22), and the checkout may only run for the basket's
 * owner. The guest account created at checkout start takes it over; a basket
 * that already has another owner is never touched.
 *
 * The storefront keeps the chosen delivery address in `OEGQL_DELADDRESSID`
 * (when the storefront is installed); it is set to the guest's address of
 * this checkout so that column and the account agree.
 *
 * @since 1.5.0
 */
class OxidGuestBasketClaim implements GuestBasketClaimInterface
{
    private const STOREFRONT_DELIVERY_ADDRESS_FIELD = 'oegql_deladdressid';

    public function claim(string $basketId, GuestUser $guest): void
    {
        $owner = $this->ownerOf($basketId);
        if ($owner === null) {
            throw new ShopOrderException(
                message: 'Basket not found',
                errorCode: 'basket_not_found',
                context: ['basket_id' => $basketId]
            );
        }
        if ($owner !== '' && $owner !== $guest->userId) {
            throw new ShopOrderException(
                message: 'Basket belongs to another user',
                errorCode: 'basket_forbidden',
                context: ['basket_id' => $basketId]
            );
        }

        $this->assign($basketId, $guest->userId, $guest->deliveryAddressId);
    }

    /**
     * Seam: the owner id of the basket row ('' without one), null without the row.
     */
    protected function ownerOf(string $basketId): ?string
    {
        $row = $this->loadRow($basketId);

        return $row === null ? null : (string) $row->getFieldData('oxuserid');
    }

    /**
     * Seam: write the owner (and the delivery address, where the storefront has the column).
     */
    protected function assign(string $basketId, string $userId, ?string $deliveryAddressId): void
    {
        $row = $this->loadRow($basketId);
        if ($row === null) {
            return;
        }

        $row->oxuserbaskets__oxuserid = new Field($userId, Field::T_RAW);
        $property = 'oxuserbaskets__' . self::STOREFRONT_DELIVERY_ADDRESS_FIELD;
        if (isset($row->$property)) {
            $row->$property = new Field($deliveryAddressId ?? '', Field::T_RAW);
        }
        $row->save();
    }

    private function loadRow(string $basketId): ?UserBasket
    {
        /** @var UserBasket $row */
        $row = oxNew(UserBasket::class);

        return $row->load($basketId) ? $row : null;
    }
}
