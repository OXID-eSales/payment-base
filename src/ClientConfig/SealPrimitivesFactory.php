<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\SealPrimitivesInterface;

/**
 * Picks the primitives of the sealed client config: those of the sealed-channel module when it is installed, payment-base's
 * own otherwise. No module setting and no activation order is involved: the autoloader decides, so the config
 * seals on every shop, with or without the module.
 *
 * @since 1.5.0
 */
final class SealPrimitivesFactory
{
    private const SEALED_CHANNEL_CRYPTO = 'OxidEsales\\SealedChannel\\Crypto\\P256';

    private function __construct()
    {
    }

    public static function sealedChannelAvailable(): bool
    {
        return class_exists(self::SEALED_CHANNEL_CRYPTO);
    }

    /**
     * @param bool|null $sealedChannel null: ask the autoloader
     */
    public static function create(?bool $sealedChannel = null): SealPrimitivesInterface
    {
        return ($sealedChannel ?? self::sealedChannelAvailable())
            ? new SealedChannelSealPrimitives()
            : new BuiltInSealPrimitives();
    }
}
