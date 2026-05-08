<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

/**
 * Interface for events representing contract termination without fulfillment.
 *
 * This interface groups events where a contract ends before completion:
 * - ContractCancelledEvent (user or system cancellation)
 * - ContractExpiredEvent (timeout/expiration)
 *
 * Handlers that need to perform cleanup or finalization when contracts
 * terminate should accept this interface instead of using union types.
 *
 * Example:
 * <code>
 * class ContractCleanupHandler extends AbstractHandler
 * {
 *     public function handle(object $event): void
 *     {
 *         if (!$event instanceof ContractTerminatedEventInterface) {
 *             return;
 *         }
 *
 *         $contract = $event->getContract();
 *         // Perform cleanup...
 *     }
 * }
 * </code>
 *
 * @since 1.0.0
 */
interface ContractTerminatedEventInterface extends ContractEventInterface
{
    // This interface doesn't add new methods - it serves as a semantic marker
    // for grouping termination events, allowing handlers to depend on an
    // abstraction rather than concrete event types or union types.
}
