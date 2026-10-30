<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

/**
 * Answers whether the current session already has a checkout attempt in
 * flight that a repeated "Order now" should simply rejoin.
 *
 * @since MOL-18
 */
interface InFlightCheckoutAttemptResolverInterface
{
    /**
     * @param string $provider        the provider that starts the checkout now (for example `mollie`);
     *                                an attempt of another provider is never rejoined
     * @param float $liveBasketTotal gross total of the session basket right now
     *
     * @return string|null the PSP checkout URL of the in-flight attempt to
     *                     redirect to again, or null when a new attempt is due
     */
    public function resolve(string $provider, float $liveBasketTotal): ?string;
}
