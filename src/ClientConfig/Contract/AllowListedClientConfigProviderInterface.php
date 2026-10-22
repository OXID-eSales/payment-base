<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

/**
 * A provider that declares which value names its browser SDK may receive
 * (`stripe: publishableKey`, `paypal: clientId`, `mollie: profileId, testMode`).
 * ClientConfigGuard then refuses every other name, whatever its value looks
 * like: a secret under a harmless name no longer passes the deny shapes.
 *
 * A separate interface, not a new method on ClientConfigProviderInterface:
 * adding a method there would be a fatal error for every provider module
 * built against 1.4.0. A provider that does not implement this keeps working
 * under the deny shapes alone; implementing it is the upgrade path.
 *
 * @since 1.4.0
 */
interface AllowListedClientConfigProviderInterface extends ClientConfigProviderInterface
{
    /**
     * @return list<string> the keys `publicValues()` may contain
     */
    public function publicValueNames(): array;
}
