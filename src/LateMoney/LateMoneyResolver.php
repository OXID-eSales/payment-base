<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskFlagInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VR-23 T1 - see {@see LateMoneyResolverInterface}.
 *
 * Generalises what the provider modules did each for themselves for replaced checkout attempts, and adds the
 * case they all missed: the provider reports `paid` for a contract the shop already cancelled (the nightly
 * stale-order cleanup, an admin cancel), which used to be answered 5xx until the provider gave up.
 *
 * Not recorded: payments that hold no money (an asynchronous method completes first and succeeds later) and
 * refunds the provider refused (the next delivery retries). Logged as an error whenever money moved: the
 * merchant must see money that came and went back.
 *
 * @since 1.8.0
 */
class LateMoneyResolver implements LateMoneyResolverInterface
{
    private const IDEMPOTENCY_PREFIX = 'late:';

    public function __construct(
        private readonly ProviderPaymentGatewaysInterface $gateways,
        private readonly ContractRepositoryInterface $contracts,
        private readonly PaymentAtRiskFlagInterface $atRisk,
        private readonly LoggerInterface $logger,
        private readonly ThrowableSummaryInterface $throwableSummary,
    ) {
    }

    public function resolve(PaymentContractInterface $contract, string $providerPaymentId): LateMoneyOutcome
    {
        $earlier = LateMoneyRecords::outcomeFor($contract, $providerPaymentId);
        if ($earlier !== null) {
            return LateMoneyOutcome::alreadySettled($earlier);
        }

        $gateway = $this->gateways->forContract($contract);
        $key = self::IDEMPOTENCY_PREFIX . $providerPaymentId;

        try {
            $outcome = $this->giveBack($gateway, $providerPaymentId, $key);
        } catch (Throwable $e) {
            return $this->refused($contract, $providerPaymentId, $e);
        }

        if ($outcome->kind === LateMoneyKind::NoMoney) {
            return $outcome;
        }

        LateMoneyRecords::record($contract, $providerPaymentId, $this->recordOf($outcome));
        $this->contracts->save($contract);
        $this->logger->error('[LateMoneyResolver] money on a contract that can no longer take it was given back', [
            'contractId' => $contract->getId(),
            'orderId' => $contract->getOrderId(),
            'state' => $contract->getStateValue(),
            'providerPaymentId' => $providerPaymentId,
            'outcome' => $outcome->kind->value,
        ]);

        return $outcome;
    }

    private function giveBack(
        ProviderPaymentGatewayInterface $gateway,
        string $providerPaymentId,
        string $idempotencyKey
    ): LateMoneyOutcome {
        $state = $gateway->statusOf($providerPaymentId)->state;

        if ($state === ProviderPaymentState::Authorized) {
            $gateway->release($providerPaymentId, $idempotencyKey);

            return LateMoneyOutcome::released();
        }

        if ($state === ProviderPaymentState::Paid) {
            return LateMoneyOutcome::refunded($gateway->refund($providerPaymentId, $idempotencyKey));
        }

        return LateMoneyOutcome::noMoney();
    }

    private function recordOf(LateMoneyOutcome $outcome): string
    {
        return $outcome->kind === LateMoneyKind::Refunded ? 'refunded:' . $outcome->reference : $outcome->kind->value;
    }

    /**
     * The provider answers 200 to a webhook for this: the flag and the owner mail are the safety net, not a
     * retry storm. The money stays unrecorded, so the next delivery (or the merchant's retry) asks again.
     */
    private function refused(
        PaymentContractInterface $contract,
        string $providerPaymentId,
        Throwable $cause
    ): LateMoneyOutcome {
        $this->logger->error('[LateMoneyResolver] the provider would not give late money back', [
            'contractId' => $contract->getId(),
            'orderId' => $contract->getOrderId(),
            'providerPaymentId' => $providerPaymentId,
            'throwable' => $this->throwableSummary->fromThrowable($cause),
        ]);
        $this->atRisk->raise($contract, AtRiskReason::LateMoneyNotReturned, $providerPaymentId);

        return LateMoneyOutcome::failed($cause::class);
    }
}
