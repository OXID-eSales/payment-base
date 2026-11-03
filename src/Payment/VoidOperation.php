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
 * Releases an open authorisation. No money moves, nothing is booked: the contract's state follows the provider's
 * own report of the release (its webhook), as it does for every state change the PSP makes.
 *
 * @since 1.7.0
 */
final class VoidOperation extends AbstractMoneyOperation
{
    public function __construct(
        private readonly CapturePolicyInterface $policy,
        private readonly ProviderGatewayRegistryInterface $gateways,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    public function kind(): string
    {
        return AdminPaymentOperation::VOID;
    }

    public function right(): string
    {
        return PaymentRightsProvider::CAPTURE;
    }

    public function movesMoney(): bool
    {
        return false;
    }

    public function perform(PaymentContractInterface $contract, AdminPaymentOperation $operation, string $idempotencyKey): ?PaymentRefusal
    {
        if ($this->policy->remaining($contract) <= 0.0) {
            return PaymentRefusal::invalid('orderId', 'not_voidable', 'Only an open authorisation can be voided; refund a captured payment instead');
        }
        $gateway = $this->gateways->cancelFor((string) $contract->getProvider());
        if ($gateway === null) {
            return $this->unavailable();
        }
        $outcome = $this->throughGateway($contract, $operation->name(), static function () use ($gateway, $contract, $idempotencyKey, $operation): bool {
            $gateway->cancel(new CancelRequest($contract, $idempotencyKey, $operation->reason));

            return true;
        });

        return $outcome instanceof PaymentRefusal ? $outcome : null;
    }
}
