<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\DataType;

use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartResult;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * Result of `<provider>CheckoutStart`.
 *
 * @since 3.0.0
 */
#[Type]
final class CheckoutStartResult
{
    public function __construct(private readonly HeadlessStartResult $result)
    {
    }

    #[Field]
    public function contractId(): string
    {
        return $this->result->contractId;
    }

    /**
     * Present this on `<provider>CheckoutReturn` / `Cancel`; it authorises them.
     */
    #[Field]
    public function contractToken(): string
    {
        return $this->result->contractToken;
    }

    #[Field]
    public function providerName(): string
    {
        return $this->result->providerName;
    }

    /**
     * The shop order number already drawn for this checkout.
     */
    #[Field]
    public function orderNumber(): ?string
    {
        return $this->result->orderNumber;
    }

    /**
     * Send the shopper here for `renderMode = redirect`.
     */
    #[Field]
    public function redirectUrl(): ?string
    {
        return $this->result->redirectUrl;
    }

    /**
     * Mount the provider's embedded / custom UI with this for the other render modes.
     */
    #[Field]
    public function clientSecret(): ?string
    {
        return $this->result->clientSecret;
    }

    #[Field]
    public function renderMode(): string
    {
        return $this->result->renderMode;
    }
}
