<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

use OxidEsales\PaymentBase\ClientConfig\Exception\UnsafeClientConfig;

/**
 * @since 1.4.0
 */
interface ClientConfigGuardInterface
{
    /**
     * @param array<mixed> $values
     * @param list<string>|null $allowedNames the names the provider declared; null when it declared none, in which
     *        case only the deny shapes apply
     *
     * @return array<string, string|bool>
     *
     * @throws UnsafeClientConfig
     */
    public function assertPublic(array $values, ?array $allowedNames = null): array;
}
