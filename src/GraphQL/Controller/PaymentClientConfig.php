<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Controller;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigPublisherInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\TokenIdReaderInterface;
use OxidEsales\PaymentBase\GraphQL\DataType\SealedClientConfig;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * `paymentClientConfig(provider, clientPublicKey): SealedClientConfig!` - the
 * public values a provider's browser SDK needs, sealed for the browser that
 * asked and bound to the bearer token (docs/payments-keys.md).
 *
 * Deliberately no #[Logged]: a guest's anonymous token must be enough, like
 * for the basket. The right alone keeps out requests without a valid token.
 *
 * @since 1.4.0
 */
final class PaymentClientConfig
{
    public function __construct(
        private readonly ClientConfigPublisherInterface $publisher,
        private readonly TokenIdReaderInterface $tokenIdReader,
    ) {
    }

    /**
     * @param string $provider        stripe | paypal | mollie
     * @param string $clientPublicKey the browser's ephemeral P-256 key, raw 65 bytes, base64url
     */
    #[Query]
    #[Right('PAYMENT_CLIENT_CONFIG')]
    public function paymentClientConfig(string $provider, string $clientPublicKey): SealedClientConfig
    {
        return new SealedClientConfig(
            $this->publisher->sealedFor($provider, $clientPublicKey, $this->tokenIdReader->tokenId())
        );
    }
}
