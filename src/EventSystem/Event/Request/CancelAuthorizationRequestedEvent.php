<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Request;

/**
 * Provider-neutral "cancel an open authorization" event. Maps to the PSP's
 * own cancel-auth call via the broker + translator.
 */
readonly class CancelAuthorizationRequestedEvent extends AbstractProviderRequestEvent
{
}
