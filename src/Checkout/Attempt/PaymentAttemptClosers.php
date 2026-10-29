<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Attempt;

use LogicException;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Hands the attempt to its provider's closer (tag `oe.payment.attempt_closer`).
 * A provider without a closer is a wiring error.
 *
 * @since OPC-175
 */
class PaymentAttemptClosers implements PaymentAttemptClosersInterface
{
    /**
     * @param iterable<PaymentAttemptCloserInterface> $closers
     */
    public function __construct(private readonly iterable $closers)
    {
    }

    public function close(PaymentContractInterface $contract): AttemptClosure
    {
        $provider = (string) $contract->getProvider();
        foreach ($this->closers as $closer) {
            if ($closer->provider() === $provider) {
                return $closer->close($contract);
            }
        }

        throw new LogicException(sprintf(
            'No payment attempt closer for provider "%s" (contract %s)',
            $provider,
            (string) $contract->getId()
        ));
    }
}
