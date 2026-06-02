<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Request;

/**
 * Provider-neutral "capture a previously-authorized payment" event.
 * Amount = null → full remaining authorization captured.
 */
readonly class CaptureRequestedEvent extends AbstractProviderRequestEvent
{
}
