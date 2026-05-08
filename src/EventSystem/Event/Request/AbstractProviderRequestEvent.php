<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Request;

use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

/**
 * Base class for provider-agnostic request events that the broker routes to
 * the active provider's translator.
 *
 * External plugins (stock, returns, CRM, fraud) subclass this to introduce
 * their own domain-specific request events without forking
 * `payment-base`. The broker + `ProviderEventTranslatorInterface`
 * contract stay stable — that is the public extension surface (OCP).
 *
 * Subclasses are deliberately thin: carry `EventContext` + a nullable
 * amount + a nullable reason. Anything richer is a signal to build a
 * PSP-specific event instead (they already exist per provider).
 */
abstract readonly class AbstractProviderRequestEvent implements EventInterface
{
    public function __construct(
        protected EventContextInterface $context,
        protected ?float $amount = null,
        protected ?string $reason = null,
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
