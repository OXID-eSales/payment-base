<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use Psr\Log\LoggerInterface;

/**
 * Refunds a captured payment. The PSP's refund is booked by its refund id through the refund ledger, which the
 * provider's webhook uses too: whichever reports it first books it, the other changes nothing.
 *
 * @since 1.7.0
 */
final class RefundOperation extends AbstractMoneyOperation
{
    public function __construct(
        private readonly RefundPolicyInterface $policy,
        private readonly ProviderGatewayRegistryInterface $gateways,
        private readonly ProviderRefundLedgerInterface $ledger,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    public function kind(): string
    {
        return AdminPaymentOperation::REFUND;
    }

    public function right(): string
    {
        return PaymentRightsProvider::REFUND;
    }

    public function movesMoney(): bool
    {
        return true;
    }

    public function perform(PaymentContractInterface $contract, AdminPaymentOperation $operation, string $idempotencyKey): ?PaymentRefusal
    {
        $currency = $contract->getCurrency();
        $amount = AmountRule::roundedTo((float) $operation->amount, $currency);
        $refusal = $this->refusalOf($this->policy->decide($contract, $amount, $currency));
        if ($refusal !== null) {
            return $refusal;
        }
        $gateway = $this->gateways->refundFor((string) $contract->getProvider());
        if ($gateway === null) {
            return $this->unavailable();
        }
        $result = $this->throughGateway($contract, $operation->name(), static fn (): RefundResult => $gateway->refund(
            new RefundRequest($contract, $amount, $currency, $idempotencyKey, null, $operation->reason, $operation->description)
        ));
        if ($result instanceof PaymentRefusal) {
            return $result;
        }

        return $this->book($contract, $result);
    }

    private function book(PaymentContractInterface $contract, RefundResult $result): ?PaymentRefusal
    {
        if ($result->status === RefundResult::FAILED) {
            return PaymentRefusal::invalid('orderId', 'provider_failed', 'The payment provider reported the refund as failed');
        }
        $this->ledger->book((string) $contract->getId(), $result->providerRefundId, $result->amount, $contract->getCurrency(), 'admin', $result->status === RefundResult::SUCCEEDED);

        return null;
    }
}
