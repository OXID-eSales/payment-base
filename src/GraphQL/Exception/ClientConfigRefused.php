<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Exception;

use OxidEsales\GraphQL\Base\Exception\Error;
use OxidEsales\GraphQL\Base\Exception\ErrorCategories;
use OxidEsales\PaymentBase\ClientConfig\Exception\ClientConfigRequestRefused;

/**
 * What the client sees when `paymentClientConfig` refuses its request: client-safe (graphql-base `Error`), category
 * request error, and the message of the domain exception, unchanged. Headless clients match on exactly this, so it
 * carries no `errorCode` extension (it never had one).
 *
 * @since 3.6.0
 */
final class ClientConfigRefused extends Error
{
    public function __construct(ClientConfigRequestRefused $refusal)
    {
        parent::__construct(
            message: $refusal->getMessage(),
            previous: $refusal,
            category: ErrorCategories::REQUESTERROR
        );
    }

    public function getCategory(): string
    {
        return ErrorCategories::REQUESTERROR;
    }
}
