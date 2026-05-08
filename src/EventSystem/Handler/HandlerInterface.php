<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

/**
 * Interface for all event handlers.
 *
 * Handlers must implement a handle() method that accepts
 * a specific event type and returns void.
 *
 * The generic object parameter allows type-specific implementations
 * while still enforcing the contract that all handlers have a handle() method.
 *
 * Example implementation:
 * <code>
 * class ContractCreationHandler implements HandlerInterface
 * {
 *     public function handle(PaymentInitiatedEvent $event): void
 *     {
 *         // Handler logic here
 *     }
 *
 *     public static function getHandledEventClass(): string
 *     {
 *         return PaymentInitiatedEvent::class;
 *     }
 * }
 * </code>
 *
 * @since 1.0.0
 */
interface HandlerInterface
{
    /**
     * Handle an event.
     *
     * Each handler implementation should type-hint the specific event class
     * it handles (e.g., PaymentInitiatedEvent, ContractCreatedEvent).
     *
     * @param object $event The event to handle
     * @return void
     */
    public function handle(object $event): void;

    /**
     * Returns the fully qualified class name of the event this handler handles.
     *
     * @return string The event class name
     */
    public static function getHandledEventClass(): string;
}
