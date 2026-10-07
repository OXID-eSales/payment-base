<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextInterface;

/**
 * Remembers the checkout attempt THIS session has open.
 *
 * The scope is deliberate. An attempt left open in another session or on
 * another device may still be paid at the PSP, so cleaning up by user id would
 * storno an order somebody is in the middle of paying for. Only what this
 * session opened may be retired by this session, which is why the shop session
 * - not the contract table - is where it is recorded.
 *
 * Sprint 15 / S2: "session" became a {@see CheckoutContextInterface}. For the
 * Twig checkout it still is the session; for a headless checkout it is the
 * entered scope's persisted context. Same key, same rule.
 *
 * @since STRP-171
 */
class OpenCheckoutAttemptRegistry implements OpenCheckoutAttemptRegistryInterface
{
    public const SESSION_KEY = 'oepb_open_checkout_contract_id';

    public function __construct(private readonly CheckoutContextInterface $context)
    {
    }

    public function remember(string $contractId): void
    {
        $this->context->set(self::SESSION_KEY, $contractId);
    }

    /**
     * Returns the attempt this session had open and forgets it, so the same
     * contract is never cleaned twice - the second pass would find it already
     * cancelled and report a failure that never happened.
     */
    public function takePrevious(): ?string
    {
        $stored = $this->peek();
        $this->context->remove(self::SESSION_KEY);

        return $stored;
    }

    public function peek(): ?string
    {
        $stored = $this->context->get(self::SESSION_KEY);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }
}
