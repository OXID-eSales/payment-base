<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Event handler that updates OXPAID on orders when contracts are fulfilled.
 *
 * Sprint 27: Moved from Stripe to payment-component (provider-agnostic).
 * Sprint 14: Properly implements event-driven architecture.
 * Listens for ContractFulfilledEvent and updates the order's payment timestamp.
 *
 * Sprint 16: Uses OrderPaymentStateService for DRY compliance.
 * All OXPAID/OXTRANSSTATUS/OXTRANSID updates consolidated in single service.
 *
 * @since 1.0.0
 */
class OrderPaymentCompletedHandler implements HandlerInterface
{
    public function __construct(
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService,
        private readonly LoggerInterface $logger,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractFulfilledEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        $this->logEvent('OrderPaymentCompletedHandler::handle() START');

        if (!$event instanceof ContractFulfilledEvent) {
            $this->logEvent('OrderPaymentCompletedHandler: Wrong event type, skipping');
            return;
        }

        $contract = $event->getContract();
        $orderId = $contract->getOrderId();
        $providerOrderId = $contract->getProviderOrderId();

        $this->logEvent('OrderPaymentCompletedHandler: Processing', [
            'contractId' => $contract->getId(),
            'orderId' => $orderId,
        ]);

        if ($orderId === null || $orderId === '') {
            $this->logEvent('OrderPaymentCompletedHandler: WARNING - No order ID');
            $this->logger->warning('ContractFulfilledEvent received without order ID', [
                'contract_id' => $contract->getId(),
            ]);
            return;
        }

        // Sprint 16: Use OrderPaymentStateService for all payment state updates (DRY)
        $updated = $this->orderPaymentStateService->markOrderAsPaid(
            $orderId,
            $providerOrderId
        );

        if ($updated) {
            $this->logEvent('OrderPaymentCompletedHandler: OXPAID updated');
            $this->logger->info('Order payment completed via ContractFulfilledEvent', [
                'order_id' => $orderId,
                'contract_id' => $contract->getId(),
                'provider_order_id' => $providerOrderId,
            ]);
        }

        $this->logEvent('OrderPaymentCompletedHandler::handle() END');
    }

    /**
     * Log event to file logger for debugging.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
