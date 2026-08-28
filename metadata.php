<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

use OxidEsales\PaymentBase\Admin\PaymentAdminController;
use OxidEsales\PaymentBase\Controller\ValidationApiController;
use OxidEsales\PaymentBase\Core\Events\ModuleLifecycle;

$sMetadataVersion = '2.1';

$aModule = [
    'id'          => 'oe_payment_base',
    'title'       => [
        'de' => 'OXID Payment Base',
        'en' => 'OXID Payment Base',
    ],
    'description' => [
        'de' => 'Provider-agnostische Payment-Infrastruktur (Smart-Contract-Architektur, '
              . 'geteilter Admin-Tab "Zahlung"). Wird von den PSP-Modulen (Stripe, PayPal) genutzt.',
        'en' => 'Provider-agnostic payment infrastructure (smart-contract architecture, '
              . 'shared admin "Payment" tab). Consumed by the PSP modules (Stripe, PayPal).',
    ],
    'version'     => '1.0.0',
    'author'      => 'OXID eSales AG',
    'url'         => 'https://www.oxid-esales.com',
    'email'       => 'info@oxid-esales.com',
    'extend'      => [
        \OxidEsales\Eshop\Core\PriceList::class => \OxidEsales\PaymentBase\Eshop\Core\PriceList::class,
        // Sprint 06 — single active payment method: assign it and skip the step.
        \OxidEsales\Eshop\Application\Controller\PaymentController::class
            => \OxidEsales\PaymentBase\Eshop\Application\Controller\PaymentController::class,
        \OxidEsales\Eshop\Application\Controller\OrderController::class
            => \OxidEsales\PaymentBase\Eshop\Application\Controller\OrderController::class,
    ],
    'controllers' => [
        // OXID admin menu.xml tab-resolver needs the cl=> class map here
        // to render the Payment tab's `<a href>`. services.yaml DI is kept
        // but no longer uses `oxid.view_controller` tag — the controller
        // fetches its own dependencies via ContainerFactory inside render()
        // to avoid "Controller namespace duplication" on activation.
        'PaymentAdmin' => PaymentAdminController::class,
        // Sprint 119 (STRP-129) — central frontend validation endpoint.
        // URL: /index.php?cl=oepaymentvalidationapi&fnc=validate
        'oepaymentvalidationapi' => ValidationApiController::class,
    ],
    'templates'   => [
        '@oe_payment_base/admin/payment_admin_tab' => 'views/twig/admin/payment_admin_tab.html.twig',
    ],
    'events'      => [
        'onActivate'   => ModuleLifecycle::class . '::onActivate',
        'onDeactivate' => ModuleLifecycle::class . '::onDeactivate',
    ],
    'settings'    => [
        // Sprint 119 (STRP-129) — global per-minute rate limit for the validation
        // endpoint. Can be tightened by ops without a code deploy. PSP modules can
        // register per-plugin overrides via the tagged iterator
        // `oe.payment_base.rate_limit_override`.
        ['name' => 'iValidationApiRatePerMinute', 'type' => 'num', 'value' => '30', 'group' => 'validation'],
        // Sprint 125 (STRP-157) — enable per-line VAT calculation (default off).
        // When true, PriceList::getVatInfo() rounds VAT per line before aggregating
        // instead of grouping all lines at the same rate and rounding once.
        ['name' => 'blPaymentBasePerLineVat', 'type' => 'bool', 'value' => false, 'group' => 'per_line_vat'],
        // IFRAME-01 — "Use iframe instead of checkout button" (default off). When true, a PSP whose
        // payment handler opts in embeds its payment UI inline (iframe) via the OPC footer widget
        // instead of rendering a button that redirects to the provider's hosted page. Read
        // provider-agnostically through IframeCheckoutSettingsInterface.
        ['name' => 'blPaymentBaseUseIframe', 'type' => 'bool', 'value' => false, 'group' => 'iframe_checkout'],
        // Sprint 06 (2026-08-26) — when the shop offers exactly one payment method the
        // checkout assigns it and skips the selection step (and hides the payment block
        // on the order page). Default on: it can only fire when there is nothing to
        // choose. Turn it off to keep the confirmation step.
        [
            'name' => 'blPaymentBaseAutoAssignSinglePayment',
            'type' => 'bool',
            'value' => true,
            'group' => 'checkout_flow',
        ],
        // Sprint 07 (2026-08-27) — the shipping half. When the shop offers exactly one
        // delivery set the checkout assigns it (writing sShipSet, which core does not
        // persist on a plain render) and leaves the selector out of the payment step and
        // the carrier block out of the order page. Separate switch from the payment one:
        // a merchant may want one shortcut and not the other.
        [
            'name' => 'blPaymentBaseAutoAssignSingleShipping',
            'type' => 'bool',
            'value' => true,
            'group' => 'checkout_flow',
        ],
        // Sprint 08 (2026-08-28) — age threshold, in days, for
        // `bin/oe-console oe:payments:not_finished:cleanup`. A PSP module creates the
        // order before redirecting to the provider, so a customer who never comes back
        // leaves it at OXTRANSSTATUS = 'NOT_FINISHED' with its vouchers still spent.
        // Nothing collected those rows on a schedule before; the command does, and this
        // is how old an order has to be before it qualifies. Values below 1 are refused
        // at read time — "older than 0 days" would select the checkout in progress.
        [
            'name' => 'iPaymentBaseCleanupPeriod',
            'type' => 'num',
            'value' => '7',
            'group' => 'cleanup',
        ],
        // STRP-168 item 3 — how many minutes a checkout may sit in flight before
        // the fast sweep (which runs off inbound provider webhooks) treats it as
        // abandoned. Was a hardcoded 30 in Stripe's webhook controller, so a shop
        // selling by bank transfer or Klarna — where customers legitimately take
        // longer — could not raise it without a code change. Distinct from the
        // cleanup period above: this one clears the way for a customer retrying
        // their own checkout, that one collects orders nobody returns for.
        [
            'name' => 'iPaymentBaseStaleCheckoutMinutes',
            'type' => 'num',
            'value' => '30',
            'group' => 'cleanup',
        ],
    ],
];
