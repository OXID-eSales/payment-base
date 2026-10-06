<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Exception;

use OxidEsales\GraphQL\Base\Exception\Error;
use OxidEsales\GraphQL\Base\Exception\ErrorCategories;

/**
 * Core `placeOrder` was called for a basket whose payment is contract-first.
 * Client-safe (graphql-base `Error`), category request error: the client
 * called the wrong mutation, and the message names the right one.
 *
 * @since 3.0.0
 */
final class ContractFirstPaymentCheckout extends Error
{
    public function __construct(string $paymentId, string $providerName)
    {
        parent::__construct(
            message: sprintf(
                'Payment "%s" is handled by the %s checkout: call %sCheckoutStart instead of placeOrder',
                $paymentId,
                $providerName,
                $providerName
            ),
            category: ErrorCategories::REQUESTERROR
        );
    }

    public function getCategory(): string
    {
        return ErrorCategories::REQUESTERROR;
    }
}
