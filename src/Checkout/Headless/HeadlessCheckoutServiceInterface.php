<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * The headless checkout in three calls, provider-agnostic. Each provider's
 * GraphQL mutations (`<provider>CheckoutStart` / `Return` / `Cancel`) and the
 * MCP tools are thin shells around this.
 *
 * Sprint 15 / S6 (GRAPH-QL, Option B): the contract-first flow - contract,
 * early order, PSP session, return, commit - unchanged, driven without a
 * session.
 *
 * @since 3.0.0
 */
interface HeadlessCheckoutServiceInterface
{
    /**
     * Today's "Place Order": contract (DRAFT) → early order (NOT_FINISHED) →
     * PENDING → PSP session, for the persisted user basket, by the provider
     * that owns the basket's payment.
     *
     * @throws HeadlessCheckoutException
     */
    public function start(HeadlessStartRequest $request): HeadlessStartResult;

    /**
     * Today's `checkoutSuccess`: the provider's resolver reads what the PSP
     * says, the shared chain commits. Idempotent.
     *
     * @param array<string, mixed> $providerParams what the PSP appended to the return URL (e.g. `checkoutSessionId`)
     * @throws HeadlessCheckoutException
     */
    public function return(string $contractId, string $contractToken, array $providerParams): HeadlessReturnResult;

    /**
     * Today's `checkoutCancel`: retire the attempt (storno the NOT_FINISHED
     * order, cancel the contract) unless money was taken.
     *
     * @throws HeadlessCheckoutException
     */
    public function cancel(string $contractId, string $contractToken): HeadlessCancelResult;
}
