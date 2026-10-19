<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Service;

use OxidEsales\GraphQL\Base\Service\Token;
use OxidEsales\PaymentBase\ClientConfig\Contract\TokenIdReaderInterface;

/**
 * Reads the `tokenid` claim through graphql-base's Token service. The
 * service is optional (`@?` in services.yaml): without graphql-base there is
 * no bearer token and the id is empty.
 *
 * @since 1.4.0
 */
final class BearerTokenIdReader implements TokenIdReaderInterface
{
    public function __construct(private readonly ?Token $token = null)
    {
    }

    public function tokenId(): string
    {
        $claim = $this->token?->getTokenClaim(Token::CLAIM_TOKENID, '');

        return is_string($claim) ? $claim : '';
    }
}
