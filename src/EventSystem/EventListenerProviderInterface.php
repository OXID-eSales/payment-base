<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem;

/**
 * Provides event listeners/handlers for the EventDispatcher.
 * This is the bridge between DI container and event system.
 *
 * @since 1.0.0
 */
interface EventListenerProviderInterface
{
    /**
     * Returns all registered listeners for an event class.
     *
     * @param string $eventClass Fully qualified event class name
     * @return array<callable> Array of callables that handle the event
     */
    public function getListenersForEvent(string $eventClass): array;

    /**
     * Registers a listener for an event class.
     *
     * @param string $eventClass Event class to listen for
     * @param callable $listener Handler callable
     * @param int $priority Higher priority = executed first (default: 0)
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void;
}
