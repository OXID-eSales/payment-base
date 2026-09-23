<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;

/**
 * Core's Order::setUser() writes OXDEL* only when a separate oxaddress row
 * was selected, so a shopper who ships to their billing address leaves every
 * OXDEL* column empty and the admin Addresses tab shows no Shipping Address
 * at all. This mirrors OXBILL* onto OXDEL* in that case; the caller owns
 * persisting the order.
 *
 * @since 2026-09-23 (Sprint 10)
 */
final class OrderShippingAddressCopier
{
    private const FIELD_SUFFIXES = [
        'company', 'fname', 'lname', 'street', 'streetnr', 'addinfo', 'city',
        'countryid', 'stateid', 'zip', 'fon', 'fax', 'sal',
    ];

    /**
     * @return bool True when the billing address was copied, false when the
     *              order already had a shipping address and was left alone.
     */
    public function copyBillingWhenShippingEmpty(Order $order): bool
    {
        if ($this->hasShippingAddress($order)) {
            return false;
        }

        foreach (self::FIELD_SUFFIXES as $suffix) {
            $order->{"oxorder__oxdel$suffix"} = new Field(
                (string) $order->getFieldData("oxbill$suffix"),
                Field::T_RAW
            );
        }

        return true;
    }

    private function hasShippingAddress(Order $order): bool
    {
        return (string) $order->getFieldData('oxdellname') !== ''
            || (string) $order->getFieldData('oxdelfname') !== '';
    }
}
