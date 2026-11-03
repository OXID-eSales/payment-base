<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\IdempotencyClaim;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotencyInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReaderInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationRules;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\RequestShopOrdersInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Capture, refund and void, one path for every admin door. The order of things is the point:
 *
 *  1. audit opens (a denied attempt leaves its row), the right and, for money movements, the step-up are checked;
 *  2. the call's shape, the shop, the contract;
 *  3. the order lock: two calls of one order never run together, whatever their idempotency keys;
 *  4. the atomic idempotency claim: the same call twice is one call;
 *  5. under the lock, on the contract as it is now: policy, the provider's gateway, the ledger.
 *
 * A refusal releases the claim, the call may be corrected and tried again. A failure after the provider may have
 * moved money (the booking) leaves the claim in flight: the retry is told so until it runs out, and the PSP gets the
 * same idempotency key then.
 *
 * @since 1.7.0
 */
final class MoneyOperationRunner implements MoneyOperationRunnerInterface
{
    /** @var array<string, MoneyOperationInterface> */
    private array $operations = [];

    /**
     * @param iterable<MoneyOperationInterface> $operations
     */
    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly PaymentContractDetailsReaderInterface $details,
        private readonly RequestShopOrdersInterface $shopOrders,
        private readonly OrderLockInterface $lock,
        private readonly OperationIdempotencyInterface $idempotency,
        iterable $operations,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($operations as $operation) {
            $this->operations[$operation->kind()] = $operation;
        }
    }

    public function run(AdminPaymentOperation $operation, AdminChannelInterface $channel): PaymentOperationResult
    {
        $kind = $this->operations[$operation->kind] ?? throw new InvalidArgumentException('No money operation of kind ' . $operation->kind);

        return $channel->audit()->audited(
            $operation->name(),
            $operation->orderId,
            $operation->changedFields(),
            $operation->amount,
            function () use ($operation, $channel, $kind): PaymentOperationResult {
                $channel->requireRight($kind->right());
                if ($kind->movesMoney()) {
                    $channel->requireStepUp();
                }
                $result = $this->perform($operation, $kind);
                $this->logOutcome($operation, $result);

                return $result;
            },
        );
    }

    private function perform(AdminPaymentOperation $operation, MoneyOperationInterface $kind): PaymentOperationResult
    {
        $refusal = $operation->inputRefusal();
        if ($refusal !== null) {
            return PaymentOperationResult::refused($refusal);
        }
        if (!$this->shopOrders->contains($operation->orderId) || $this->contracts->findByOrderId($operation->orderId) === null) {
            return PaymentOperationResult::refused(PaymentRefusal::notFound());
        }
        try {
            return $this->lock->runLocked($operation->orderId, fn (): PaymentOperationResult => $this->claimed($operation, $kind));
        } catch (OrderLockUnavailableException) {
            return PaymentOperationResult::refused(self::inProgress());
        }
    }

    private function claimed(AdminPaymentOperation $operation, MoneyOperationInterface $kind): PaymentOperationResult
    {
        // Read under the lock: the bound is checked against the money as it is now, not as it was before the wait.
        $contract = $this->contracts->findByOrderId($operation->orderId);
        $payment = $this->details->findByOrderId($operation->orderId);
        if ($contract === null || $payment === null) {
            return PaymentOperationResult::refused(PaymentRefusal::notFound());
        }
        $claim = $this->idempotency->claim($operation->idempotencyName(), $operation->orderId, $this->discriminator($operation, $contract));
        if ($claim->outcome === IdempotencyClaim::COMPLETED) {
            return PaymentOperationResult::done($payment);
        }
        if ($claim->outcome !== IdempotencyClaim::FRESH) {
            return PaymentOperationResult::refused(self::inProgress());
        }

        return $this->execute($operation, $kind, $contract, $payment, $claim);
    }

    private function execute(
        AdminPaymentOperation $operation,
        MoneyOperationInterface $kind,
        PaymentContractInterface $contract,
        PaymentContractDetails $payment,
        IdempotencyClaim $claim,
    ): PaymentOperationResult {
        try {
            $refusal = PaymentOperationRules::textRefusal($operation, $payment->actionOptions())
                ?? $kind->perform($contract, $operation, $claim->key);
        } catch (Throwable $unknownOutcome) {
            $this->logger->critical('Admin payment operation ended with an unknown outcome; its claim stays', [
                'operation' => $operation->name(),
                'orderId' => $operation->orderId,
                'exception' => $unknownOutcome::class,
            ]);
            throw $unknownOutcome;
        }
        if ($refusal !== null) {
            $this->idempotency->release($claim);

            return PaymentOperationResult::refused($refusal);
        }
        $this->idempotency->complete($claim);

        return PaymentOperationResult::done($this->details->findByOrderId($operation->orderId) ?? $payment);
    }

    /**
     * The client's key, or what makes two calls "the same" without one: the order's money and the amount asked.
     */
    private function discriminator(AdminPaymentOperation $operation, PaymentContractInterface $contract): string
    {
        if ($operation->clientKey !== null) {
            return 'client:' . $operation->clientKey;
        }

        return sprintf(
            'state:%s|%.4f|%.4f|amount:%s',
            $contract->getStateValue(),
            $contract->getCapturedAmount() ?? 0.0,
            $contract->getRefundedAmount() ?? 0.0,
            $operation->amount === null ? '-' : (string) $operation->amount,
        );
    }

    private static function inProgress(): PaymentRefusal
    {
        return PaymentRefusal::invalid('idempotencyKey', 'in_progress', 'The same call is still running');
    }

    private function logOutcome(AdminPaymentOperation $operation, PaymentOperationResult $result): void
    {
        $refusal = $result->refusal();
        $this->logger->info('Admin payment operation', [
            'operation' => $operation->name(),
            'orderId' => $operation->orderId,
            'outcome' => $refusal === null ? 'done' : $refusal->code,
        ]);
    }
}
