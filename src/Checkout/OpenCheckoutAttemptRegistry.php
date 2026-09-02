<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;

/**
 * Remembers the checkout attempt THIS session has open.
 *
 * The scope is deliberate. An attempt left open in another session or on
 * another device may still be paid at the PSP, so cleaning up by user id would
 * storno an order somebody is in the middle of paying for. Only what this
 * session opened may be retired by this session, which is why the shop session
 * - not the contract table - is where it is recorded.
 *
 * @since STRP-171
 */
class OpenCheckoutAttemptRegistry implements OpenCheckoutAttemptRegistryInterface
{
    public const SESSION_KEY = 'oepb_open_checkout_contract_id';

    public function __construct(private readonly SessionAdapterInterface $session)
    {
    }

    public function remember(string $contractId): void
    {
        $this->session->setVariable(self::SESSION_KEY, $contractId);
    }

    /**
     * Returns the attempt this session had open and forgets it, so the same
     * contract is never cleaned twice - the second pass would find it already
     * cancelled and report a failure that never happened.
     */
    public function takePrevious(): ?string
    {
        $stored = $this->session->getVariable(self::SESSION_KEY);
        $this->session->setVariable(self::SESSION_KEY, null);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }
}
