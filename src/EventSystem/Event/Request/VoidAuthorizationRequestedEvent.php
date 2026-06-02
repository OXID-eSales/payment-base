<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Request;

/**
 * Provider-neutral "void a previously-authorized payment" event. PSPs that
 * don't distinguish void from cancel may ignore this (translator returns
 * null and the broker logs a noop).
 */
readonly class VoidAuthorizationRequestedEvent extends AbstractProviderRequestEvent
{
}
