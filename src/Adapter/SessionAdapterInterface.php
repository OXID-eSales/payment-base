<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * Interface for session operations.
 *
 * Allows handlers to be unit tested without triggering OXID container builds.
 *
 * SOLID Principles:
 * - ISP: Focused interface with session operations only
 * - DIP: Handlers depend on this abstraction, not OXID Registry
 *
 * @since 2.0.0
 */
interface SessionAdapterInterface
{
    /**
     * Get the current basket from session.
     *
     * @return object|null Current basket or null if not set
     */
    public function getBasket(): ?object;

    /**
     * Set a session variable.
     *
     * @param string $name Variable name
     * @param mixed $value Variable value
     */
    public function setVariable(string $name, mixed $value): void;

    /**
     * Get a session variable.
     *
     * @param string $name Variable name
     * @return mixed Variable value or null if not set
     */
    public function getVariable(string $name): mixed;
}
