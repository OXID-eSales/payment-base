<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;

/**
 * Writes an `authorization` row into `oe_payments_transaction` when a payment
 * is authorized. Stripe does this today; PayPal forgot to. Centralising it
 * here closes the gap for every provider.
 *
 * Low priority (10) so it runs AFTER the commit handler — the row references
 * an orderId that the commit handler has just materialised.
 */
final class TransactionRecordingHandler implements HandlerInterface
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentAuthorizedEvent::class;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentAuthorizedEvent) {
            return;
        }

        $context = $event->getContext();
        $providerName = $this->resolveProviderName($context);
        if ($providerName === null) {
            return;
        }

        $orderId = $context->get('orderId');
        if (!is_string($orderId) || $orderId === '') {
            return;
        }

        $contract = $context->getContract();
        $contractId = $contract?->getId();

        $transaction = new Transaction(
            id: 'auth_' . bin2hex(random_bytes(16)),
            shopId: 1,
            orderId: $orderId,
            contractId: $contractId,
            provider: $providerName,
            type: 'authorization',
            status: 'completed',
            amount: $event->getAmount(),
            currency: $event->getCurrency(),
        );
        $transaction->setProviderOrderId($event->getProviderOrderId());
        $transaction->setTransactionId($event->getAuthorizationId());

        $this->transactions->save($transaction);
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
