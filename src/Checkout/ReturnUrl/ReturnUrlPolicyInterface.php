<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\ReturnUrl;

/**
 * Where the PSP may send a shopper back to, when the client - not the shop -
 * names the URL.
 *
 * Sprint 15 / S5 (GRAPH-QL). The Twig checkout builds its own return URLs; a
 * headless client (GraphQL Storefront app, mobile app) hands them in with the
 * `CheckoutStart` mutation. That is an open-redirect surface: a crafted URL
 * would bounce a shopper who just paid to a page that also learns their
 * contract id. Every provider's headless entry point asks this policy
 * before it passes a client URL to the PSP; the rule is written once.
 *
 * @since 3.0.0
 */
interface ReturnUrlPolicyInterface
{
    /**
     * @return string the URL, unchanged, when it may be used
     * @throws ReturnUrlRejectedException otherwise, naming the reason
     */
    public function assertAllowed(string $url): string;

    public function isAllowed(string $url): bool;
}
