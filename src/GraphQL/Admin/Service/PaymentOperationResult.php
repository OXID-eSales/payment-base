<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use LogicException;

/**
 * The outcome of an admin payment operation: the payment as it stands afterwards, or the refusal.
 *
 * @since 1.5.0
 */
final readonly class PaymentOperationResult
{
    private function __construct(private ?PaymentContractDetails $payment, private ?PaymentRefusal $refusal)
    {
    }

    public static function done(PaymentContractDetails $payment): self
    {
        return new self($payment, null);
    }

    public static function refused(PaymentRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function refusal(): ?PaymentRefusal
    {
        return $this->refusal;
    }

    public function payment(): PaymentContractDetails
    {
        return $this->payment ?? throw new LogicException('A refused operation has no payment');
    }
}
