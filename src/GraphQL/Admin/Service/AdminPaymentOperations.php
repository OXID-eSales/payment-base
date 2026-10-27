<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use Psr\Log\LoggerInterface;

/**
 * The admin API's capture, refund and void: payment-base decides whether the call may run (step-up, shape,
 * shop, bounds, idempotency) and hands it to the provider module behind the order's contract through the
 * classic Payment tab's boundary. Whether the provider achieved anything is read back from the contract: a
 * provider that validated the call away reports nothing to this module, only to its own feedback channel.
 *
 * @since 1.5.0
 */
final class AdminPaymentOperations implements AdminPaymentOperationsInterface
{
    public function __construct(
        private readonly PaymentContractDetailsReaderInterface $details,
        private readonly ProviderActionRunnerInterface $provider,
        private readonly OperationIdempotencyInterface $idempotency,
        private readonly AdminStepUpInterface $stepUp,
        private readonly AdminAuditInterface $audit,
        private readonly RequestShopOrdersInterface $shopOrders,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function capture(string $orderId, ?float $amount, ?string $idempotencyKey): PaymentOperationResult
    {
        return $this->run(AdminPaymentOperation::capture($orderId, $amount, $idempotencyKey));
    }

    public function refund(string $orderId, float $amount, ?string $reason, ?string $idempotencyKey): PaymentOperationResult
    {
        return $this->run(AdminPaymentOperation::refund($orderId, $amount, $reason, $idempotencyKey));
    }

    public function void(string $orderId, ?string $idempotencyKey): PaymentOperationResult
    {
        return $this->run(AdminPaymentOperation::void($orderId, $idempotencyKey));
    }

    private function run(AdminPaymentOperation $operation): PaymentOperationResult
    {
        return $this->audit->audited(
            $operation->name(),
            $operation->orderId,
            $operation->changedFields(),
            $operation->amount,
            function () use ($operation): PaymentOperationResult {
                if ($operation->movesMoney()) {
                    $this->stepUp->require();
                }
                $result = $this->perform($operation);
                $this->logOutcome($operation, $result);

                return $result;
            },
        );
    }

    private function perform(AdminPaymentOperation $operation): PaymentOperationResult
    {
        $inputRefusal = $operation->inputRefusal();
        if ($inputRefusal !== null) {
            return PaymentOperationResult::refused($inputRefusal);
        }
        $before = $this->shopOrders->contains($operation->orderId) ? $this->details->findByOrderId($operation->orderId) : null;
        if ($before === null) {
            return PaymentOperationResult::refused(PaymentRefusal::notFound());
        }
        $claim = $this->idempotency->claim($operation->idempotencyName(), $operation->orderId, $this->discriminator($operation, $before));
        if ($claim->outcome !== IdempotencyClaim::FRESH) {
            return $this->unlessFresh($claim, $before);
        }

        return $this->execute($operation, $before, $claim);
    }

    private function unlessFresh(IdempotencyClaim $claim, PaymentContractDetails $payment): PaymentOperationResult
    {
        if ($claim->outcome === IdempotencyClaim::COMPLETED) {
            return PaymentOperationResult::done($payment);
        }

        return PaymentOperationResult::refused(PaymentRefusal::invalid('idempotencyKey', 'in_progress', 'The same call is still running'));
    }

    private function execute(AdminPaymentOperation $operation, PaymentContractDetails $before, IdempotencyClaim $claim): PaymentOperationResult
    {
        $refusal = PaymentOperationRules::refusalFor($operation, $before) ?? $this->providerRefusal($operation, $before);
        $after = $refusal === null ? $this->details->findByOrderId($operation->orderId) : null;
        if ($after !== null && $after->fingerprint() !== $before->fingerprint()) {
            $this->idempotency->complete($claim);

            return PaymentOperationResult::done($after);
        }
        $this->idempotency->release($claim);

        return PaymentOperationResult::refused($refusal ?? PaymentRefusal::invalid(
            'orderId',
            'not_confirmed',
            'The payment provider did not confirm the action; check the transactions of the payment and the provider\'s own screens',
        ));
    }

    private function providerRefusal(AdminPaymentOperation $operation, PaymentContractDetails $before): ?PaymentRefusal
    {
        $outcome = $this->provider->run($before->provider, $operation->orderId, $operation->providerAction(), $this->requestFor($operation, $before));

        return match ($outcome) {
            ProviderActionRunnerInterface::UNAVAILABLE => PaymentRefusal::invalid('orderId', 'provider_unavailable', 'No payment provider module handles this payment'),
            ProviderActionRunnerInterface::FAILED => PaymentRefusal::invalid('orderId', 'provider_failed', 'The payment provider refused or could not complete the action'),
            default => null,
        };
    }

    /**
     * The classic Payment tab's form fields.
     *
     * @return array<string, mixed>
     */
    private function requestFor(AdminPaymentOperation $operation, PaymentContractDetails $payment): array
    {
        $request = [];
        if ($operation->amount !== null) {
            $request[$operation->kind . '_amount'] = $payment->roundToMinorUnit($operation->amount);
        }
        if ($operation->reason !== null) {
            $request['refund_reason'] = $operation->reason;
        }
        if ($operation->clientKey !== null) {
            $request['idempotency_key'] = $operation->clientKey;
        }

        return $request;
    }

    private function discriminator(AdminPaymentOperation $operation, PaymentContractDetails $payment): string
    {
        if ($operation->clientKey !== null) {
            return 'client:' . $operation->clientKey;
        }

        return sprintf('state:%s|amount:%s', $payment->fingerprint(), $operation->amount === null ? '-' : (string) $payment->roundToMinorUnit($operation->amount));
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
