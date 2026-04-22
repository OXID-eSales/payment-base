<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Controller;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Return\ReturnResolverInterface;

/**
 * Sprint E trait — thin delegation to {@see CheckoutReturnResponder}.
 *
 * Provider controllers do their own PSP-specific input validation
 * (token / session-id / approval params) and contract load, then call
 * `dispatchCheckoutReturn` with the PSP's name, the loaded contract,
 * its resolver, and any PSP-specific context extras. The responder
 * handles the provider-neutral steps (event dispatch + sess_challenge)
 * and returns the orderId — or null on failure.
 *
 * Trait = delegation only; all logic lives in the DI-wired
 * `CheckoutReturnResponder` so it's testable without a trait mock.
 */
trait HandlesCheckoutReturn
{
    private ?CheckoutReturnResponder $checkoutReturnResponder = null;

    public function setCheckoutReturnResponder(CheckoutReturnResponder $responder): void
    {
        $this->checkoutReturnResponder = $responder;
    }

    /**
     * @param array<string, mixed> $extraContextKeys
     * @return string|null orderId on success, null on failure.
     */
    protected function dispatchCheckoutReturn(
        string $providerName,
        PaymentContractInterface $contract,
        ReturnResolverInterface $resolver,
        array $extraContextKeys = [],
    ): ?string {
        return $this->resolveCheckoutReturnResponder()->respond(
            $providerName,
            $contract,
            $resolver,
            $extraContextKeys,
        );
    }

    /**
     * Subclasses that live outside of constructor-DI (OXID controllers
     * instantiated via oxNew) override this to fetch the responder from
     * the container — Stripe's helper + PayPal's helper both already
     * expose that seam.
     */
    protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
    {
        if ($this->checkoutReturnResponder === null) {
            throw new \LogicException(
                'CheckoutReturnResponder not injected. Either call '
                . 'setCheckoutReturnResponder() before dispatchCheckoutReturn() '
                . 'or override resolveCheckoutReturnResponder() to fetch the '
                . 'service from the container.'
            );
        }
        return $this->checkoutReturnResponder;
    }
}
