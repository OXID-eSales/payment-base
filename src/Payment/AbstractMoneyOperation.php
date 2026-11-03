<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use Closure;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the three operations share: how a decision and a gateway's failure become the refusal the admin reads.
 * A provider's own sentence is shown as it is (it is written for the admin); any other failure is reported without
 * its text, which may carry what the provider's client library put into it.
 *
 * @since 1.7.0
 */
abstract class AbstractMoneyOperation implements MoneyOperationInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    protected function refusalOf(PolicyDecision $decision): ?PaymentRefusal
    {
        return $decision->allowed ? null : PaymentRefusal::invalid($decision->field, $decision->code, $decision->message);
    }

    protected function unavailable(): PaymentRefusal
    {
        return PaymentRefusal::invalid('orderId', 'provider_unavailable', 'No payment provider module handles this payment');
    }

    /**
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T|PaymentRefusal
     */
    protected function throughGateway(PaymentContractInterface $contract, string $operation, Closure $call): mixed
    {
        try {
            return $call();
        } catch (AdminActionRefusedException $refusal) {
            $this->logger->info('Admin payment operation refused by the provider', $this->context($contract, $operation) + ['refusalCode' => $refusal->refusalCode()]);

            return PaymentRefusal::invalid('orderId', 'provider_refused', $refusal->getMessage());
        } catch (Throwable $failure) {
            $this->logger->error('Admin payment operation failed at the provider', $this->context($contract, $operation) + ['exception' => $failure::class]);

            return PaymentRefusal::invalid('orderId', 'provider_failed', 'The payment provider refused or could not complete the action');
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function context(PaymentContractInterface $contract, string $operation): array
    {
        return ['operation' => $operation, 'contractId' => $contract->getId(), 'provider' => (string) $contract->getProvider()];
    }
}
