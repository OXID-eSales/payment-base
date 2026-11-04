<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * The merchant's shop name, or an empty string when none is set.
 *
 * The name reaches the payment provider as checkout branding, so a guessed
 * default such as "OXID eShop" would show customers a name the merchant never
 * chose. Same idiom as {@see ShopCurrency}: static, pure, never invents a value.
 */
final class ShopName
{
    /**
     * @param object|null $shop OXID shop model (carries oxshops__oxname as a Field)
     */
    public static function of(?object $shop): string
    {
        /** @var mixed $field */
        $field = $shop->oxshops__oxname ?? null;

        /** @var mixed $value */
        $value = is_object($field) ? ($field->value ?? null) : null;

        return is_string($value) ? trim($value) : '';
    }
}
