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
use Psr\Log\LoggerInterface;

/**
 * Captures an authorisation, in full when no amount is given. The PSP's capture is booked by its capture id.
 *
 * @since 1.7.0
 */
final class CaptureOperation extends AbstractMoneyOperation
{
    public function __construct(
        private readonly CapturePolicyInterface $policy,
        private readonly ProviderGatewayRegistryInterface $gateways,
        private readonly ProviderCaptureLedgerInterface $ledger,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    public function kind(): string
    {
        return AdminPaymentOperation::CAPTURE;
    }

    public function right(): string
    {
        return PaymentRightsProvider::CAPTURE;
    }

    public function movesMoney(): bool
    {
        return true;
    }

    public function perform(PaymentContractInterface $contract, AdminPaymentOperation $operation, string $idempotencyKey): ?PaymentRefusal
    {
        $currency = $contract->getCurrency();
        $amount = AmountRule::roundedTo($operation->amount ?? $this->policy->remaining($contract), $currency);
        $refusal = $this->refusalOf($this->policy->decide($contract, $amount, $currency));
        if ($refusal !== null) {
            return $refusal;
        }
        $gateway = $this->gateways->captureFor((string) $contract->getProvider());
        if ($gateway === null) {
            return $this->unavailable();
        }
        $result = $this->throughGateway($contract, $operation->name(), static fn (): CaptureResult => $gateway->capture(
            new CaptureRequest($contract, $amount, $currency, $idempotencyKey, $operation->reason)
        ));
        if ($result instanceof PaymentRefusal) {
            return $result;
        }
        $this->ledger->book((string) $contract->getId(), $result->providerCaptureId, $result->amount, $currency, 'admin');

        return null;
    }
}
