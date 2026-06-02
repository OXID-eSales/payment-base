<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Request;

/**
 * Provider-neutral "a refund is requested" event. Dispatched through the
 * broker; translator routes to the active provider's own RefundRequest event.
 *
 * Amount = null means "refund the full remaining captured amount".
 */
readonly class RefundRequestedEvent extends AbstractProviderRequestEvent
{
}
