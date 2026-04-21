<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Return\CheckoutReturnCompletedEvent;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;

/**
 * Advances the contract state machine DRAFT/NOT_FINISHED → PENDING when the
 * shopper returns from the PSP with a successful resolution.
 *
 * Resolvers stay pure-data (planning-round decision); this handler is the
 * canonical home for the pending transition. Downstream handlers
 * (`PaymentAuthorizedEventHandler` → `ContractCommitmentHandler`) take over
 * once the contract is in PENDING.
 */
final class ContractPendingTransitioner implements HandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return CheckoutReturnCompletedEvent::class;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof CheckoutReturnCompletedEvent) {
            return;
        }
        if (!$event->getResolution()->isSuccessful()) {
            return;
        }

        $context = $event->getContext();
        if ($this->resolveProviderName($context) === null) {
            return;
        }

        $contract = $context->getContract();
        if ($contract === null) {
            return;
        }

        // State machine allows NOT_FINISHED → PENDING only. DRAFT → NOT_FINISHED is
        // EarlyOrderCreationHandler's responsibility; if we see DRAFT here,
        // something upstream skipped early-order creation — skip silently rather
        // than mask the upstream bug with a state-machine exception.
        if ($contract->getStateValue() === 'not_finished') {
            $contract->transitionToPending();
            $this->contracts->save($contract);
        }
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
