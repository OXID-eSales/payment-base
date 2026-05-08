<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;

/**
 * Abstract base class for all event handlers.
 *
 * Provides common dependencies shared by most handlers:
 * - ContractRepository for accessing and persisting payment contracts
 * - EventDispatcher for emitting subsequent events (optional)
 *
 * Concrete handler implementations should extend this class and implement
 * the handle() method with their specific event type.
 *
 * Example:
 * <code>
 * class ContractConditionResolverHandler extends AbstractHandler
 * {
 *     public function handle(object $event): void
 *     {
 *         if (!$event instanceof PaymentAuthorizedEvent) {
 *             return;
 *         }
 *
 *         $contract = $this->contractRepository->findById($event->getContractId());
 *         // Handler logic using $this->contractRepository and $this->eventDispatcher
 *     }
 * }
 * </code>
 *
 * @since 1.0.0
 */
abstract class AbstractHandler implements HandlerInterface
{
    /**
     * @param ContractRepositoryInterface $contractRepository Repository for accessing payment contracts
     * @param EventDispatcherInterface|null $eventDispatcher Optional dispatcher for emitting subsequent events
     */
    public function __construct(
        protected ContractRepositoryInterface $contractRepository,
        protected ?EventDispatcherInterface $eventDispatcher = null
    ) {
    }

    /**
     * Handle an event.
     *
     * Concrete implementations should type-hint their specific event class
     * and implement the business logic.
     *
     * @param object $event The event to handle
     * @return void
     */
    abstract public function handle(object $event): void;

    /**
     * Returns the fully qualified class name of the event this handler handles.
     * Concrete implementations must override this method.
     *
     * @return string The event class name
     */
    abstract public static function getHandledEventClass(): string;
}
