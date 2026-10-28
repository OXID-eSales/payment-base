<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * @since 1.7.0
 */
interface AdminStateHelpReaderInterface
{
    /**
     * @param string|null $provider the provider whose column to add; null or not contributing: the shared columns only
     */
    public function read(?string $provider, string $language): AdminStateHelp;
}
