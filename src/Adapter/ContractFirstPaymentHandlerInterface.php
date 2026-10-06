<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * A payment handler whose "Place Order" creates a payment contract (DRAFT →
 * early order → PENDING → PSP session) instead of a finished shop order.
 *
 * Sprint 15 / S6 (GRAPH-QL). Not every `oe.payment.handler` is one: the
 * one-page checkout registers a standard handler for the shop's own payments
 * (invoice, prepayment, …), and those go through core `placeOrder` in the
 * GraphQL Storefront. The headless checkout and its placeOrder guard act only
 * on handlers that declare themselves contract-first by implementing this.
 * No methods: implementing it IS the declaration. Provider modules add it to
 * their handler in their GRAPH-QL story.
 *
 * @since 3.0.0
 */
interface ContractFirstPaymentHandlerInterface extends PaymentHandlerInterface
{
}
