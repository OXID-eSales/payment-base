<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use Closure;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;

/**
 * A provider's bounds, fetched on first use and then remembered: a provider's live source can be a network call, and
 * one read of a payment asks for the bounds several times (both amounts, the checks of a write).
 *
 * @since 1.7.0
 */
final class LazyActionBounds
{
    private bool $loaded = false;
    private ?AdminActionBounds $bounds = null;

    /**
     * @param Closure(): ?AdminActionBounds $load
     */
    public function __construct(private readonly Closure $load)
    {
    }

    public function get(): ?AdminActionBounds
    {
        if (!$this->loaded) {
            $this->bounds = ($this->load)();
            $this->loaded = true;
        }

        return $this->bounds;
    }
}
