<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTerminatedEventInterface;

/**
 * Handles contract termination events (cancellation and expiration).
 *
 * Performs cleanup operations when contracts end without fulfillment.
 * This handler demonstrates the proper use of interface hierarchies
 * to avoid union types while maintaining SOLID principles.
 *
 * @since 1.0.0
 */
class ContractCleanupHandler extends AbstractHandler
{
    public static function getHandledEventClass(): string
    {
        return ContractTerminatedEventInterface::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractTerminatedEventInterface) {
            return;
        }

        $contract = $event->getContract();

        if ($contract->getState()->isFulfilled()) {
            return;
        }

        $this->contractRepository->save($contract);
    }
}
