<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;

/**
 * Shared contract-commitment: on ContractReadyToCommitEvent, flip the existing
 * NOT_FINISHED OXID order to OK with the right transaction id, stamp OXPAID
 * (auto-capture only), commit the contract, and dispatch ContractCommittedEvent.
 *
 * Provider-neutral. Gated on `providerName` in context or on the contract so
 * a mid-migration codebase where one provider's own commit handler is still
 * active doesn't double-fire.
 */
final class ContractCommitmentHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly OrderPaymentStateServiceInterface $paymentState,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractReadyToCommitEvent::class;
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractReadyToCommitEvent) {
            return;
        }

        $context = $event->getContext();
        if ($this->resolveProviderName($context) === null) {
            return;
        }

        $contract = $event->getContract();
        if (!$contract->getState()->isReadyToCommit() || !$contract->areAllConditionsFulfilled()) {
            return;
        }

        $orderId = $contract->getOrderId();
        if ($orderId === null) {
            return;
        }

        $this->linkProviderIdsOntoOrder($orderId, $context, $contract);
        $this->commitContractAndDispatch($contract, $context, $orderId);
    }

    private function linkProviderIdsOntoOrder(
        string $orderId,
        EventContextInterface $context,
        PaymentContractInterface $contract,
    ): void {
        $transactionId = $this->resolveTransactionId($context, $contract);
        if ($transactionId === null) {
            return;
        }
        $this->paymentState->updateTransactionId($orderId, $transactionId);
        $this->paymentState->updateTransactionStatus($orderId, 'OK');
    }

    private function commitContractAndDispatch(
        PaymentContractInterface $contract,
        EventContextInterface $context,
        string $orderId,
    ): void {
        $contract->commitToOrder($orderId);
        $this->contracts->save($contract);

        $requiresCapture = $context->get('requiresCapture') === true;
        if (!$requiresCapture) {
            $this->paymentState->markOrderAsPaid($orderId, $contract->getProviderOrderId());
        }

        $context->set('orderId', $orderId);

        $this->dispatcher->dispatch(new ContractCommittedEvent($contract, $context, $orderId));
    }

    private function resolveTransactionId(
        EventContextInterface $context,
        PaymentContractInterface $contract,
    ): ?string {
        $auth = $context->get('authorizationId');
        if (is_string($auth) && $auth !== '') {
            return $auth;
        }
        $providerOrderId = $context->get('providerOrderId');
        if (is_string($providerOrderId) && $providerOrderId !== '') {
            return $providerOrderId;
        }
        $fromContract = $contract->getProviderOrderId();
        return is_string($fromContract) && $fromContract !== '' ? $fromContract : null;
    }

    private function resolveProviderName(EventContextInterface $context): ?string
    {
        $fromCtx = $context->get('providerName');
        if (is_string($fromCtx) && $fromCtx !== '') {
            return $fromCtx;
        }
        $contract = $context->getContract();
        $fromContract = $contract?->getProvider();
        return is_string($fromContract) && $fromContract !== '' ? $fromContract : null;
    }
}
