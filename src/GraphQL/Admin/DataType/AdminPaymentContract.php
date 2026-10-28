<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentTransactionDetails;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * `AdminOrder.paymentContract`: where the payment of an order stands - provider, lifecycle state, the money
 * authorised, captured and refunded, and what may still be captured or refunded. No secrets, no raw provider
 * payloads.
 *
 * `state` is the contract lifecycle: DRAFT, NOT_FINISHED, PENDING, AUTHORIZED, READY_TO_COMMIT, COMMITTED,
 * FULFILLED, CANCELLED, EXPIRED, FAILED.
 *
 * @since 1.5.0
 */
#[Type(name: 'AdminPaymentContract')]
final class AdminPaymentContract
{
    public function __construct(private readonly PaymentContractDetails $payment)
    {
    }

    /** The contract's own id, as the classic Payment panels show it. */
    #[Field]
    public function contractId(): string
    {
        return $this->payment->contractId;
    }

    /** stripe | paypal | mollie */
    #[Field]
    public function provider(): string
    {
        return $this->payment->provider;
    }

    #[Field]
    public function state(): string
    {
        return strtoupper($this->payment->state);
    }

    #[Field]
    public function amountAuthorised(): float
    {
        return $this->payment->amountAuthorised;
    }

    #[Field]
    public function amountCaptured(): float
    {
        return $this->payment->amountCaptured;
    }

    #[Field]
    public function amountRefunded(): float
    {
        return $this->payment->amountRefunded;
    }

    /** What a capture may still take; 0 when the payment is not an open authorisation. */
    #[Field]
    public function amountCapturable(): float
    {
        return $this->payment->amountCapturable();
    }

    /** What a refund may still return; 0 when nothing is left. */
    #[Field]
    public function amountRefundable(): float
    {
        return $this->payment->amountRefundable();
    }

    #[Field]
    public function currency(): string
    {
        return $this->payment->currency;
    }

    /**
     * @return AdminPaymentTransaction[]
     */
    #[Field]
    public function transactions(): array
    {
        return array_map(
            static fn (PaymentTransactionDetails $transaction): AdminPaymentTransaction => new AdminPaymentTransaction($transaction),
            $this->payment->transactions,
        );
    }
}
