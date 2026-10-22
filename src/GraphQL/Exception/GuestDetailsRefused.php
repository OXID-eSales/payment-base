<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Exception;

use OxidEsales\GraphQL\Base\Exception\Error;
use OxidEsales\GraphQL\Base\Exception\ErrorCategories;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;

/**
 * The guest details of a `<provider>CheckoutStart` were refused by the value
 * objects (missing or invalid field). Client-safe (graphql-base `Error`),
 * category request error, with the stable `errorCode` in the extensions like
 * every other headless refusal. The message names the field, never its value.
 *
 * It exists because the value objects are built while GraphQLite resolves the
 * mutation's arguments, before any controller body (and its try/catch) runs:
 * a plain HeadlessCheckoutException there would reach the client as
 * "Internal server error".
 *
 * @since 3.6.0
 */
final class GuestDetailsRefused extends Error
{
    public function __construct(HeadlessCheckoutException $refusal)
    {
        parent::__construct(
            message: $refusal->getMessage(),
            previous: $refusal,
            category: ErrorCategories::REQUESTERROR,
            extensions: ['errorCode' => $refusal->errorCode]
        );
    }

    public function getCategory(): string
    {
        return ErrorCategories::REQUESTERROR;
    }
}
