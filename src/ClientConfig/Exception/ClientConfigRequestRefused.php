<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Exception;

use DomainException;

/**
 * The client asked for something the shop refuses (an unknown provider, a malformed key). Plain domain exception on
 * purpose: GraphQL is optional, so nothing outside src/GraphQL/ may know graphql-base. The message is written for
 * the client and safe to show; the GraphQL layer turns it into a client-safe request error.
 *
 * @since 3.6.0
 */
abstract class ClientConfigRequestRefused extends DomainException
{
}
