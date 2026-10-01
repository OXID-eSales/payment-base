<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

$sLangName = 'English';

$aLang = [
    'charset'                     => 'UTF-8',
    'PAYMENT_ADMIN_TAB'           => 'Payment',
    'PAYMENT_ADMIN_NO_PROVIDER'   => 'This order was not processed through a registered online payment provider. Manual payment handling applies.',
    'PAYMENT_ADMIN_CONTRACT_ID'   => 'Contract ID',
    // Sprint 14 (MOL-10): the OXID contract state, named as such; the Help table explains the values.
    'PAYMENT_ADMIN_CONTRACT_STATE'=> 'OXID Contract Status',
    'PAYMENT_ADMIN_AMOUNT'        => 'Amount',
    'PAYMENT_ADMIN_CAPTURED'      => 'Captured',
    'PAYMENT_ADMIN_REFUNDED'      => 'Refunded',
    'PAYMENT_ADMIN_PROVIDER_ORDER'=> 'Provider order ID',
    'PAYMENT_ADMIN_ACTION_OK'     => 'Action completed successfully.',
    'PAYMENT_ADMIN_ACTION_FAILED' => 'Action failed. See the shop log for details.',

    // Module settings — group headers
    'SHOP_MODULE_GROUP_validation'             => 'Validation',
    'SHOP_MODULE_GROUP_per_line_vat'           => 'Per-line VAT',
    'SHOP_MODULE_GROUP_iframe_checkout'        => 'Iframe checkout',
    'SHOP_MODULE_GROUP_checkout_flow'          => 'Checkout flow',
    'SHOP_MODULE_GROUP_cleanup'                => 'Cleanup',

    // Module settings — field labels
    'SHOP_MODULE_iValidationApiRatePerMinute'  => 'Validation API rate limit (requests per minute)',
    'SHOP_MODULE_blPaymentBasePerLineVat'      => 'Calculate VAT per line item (round each line before summing)',
    'SHOP_MODULE_blPaymentBaseReleaseVouchersOnOrderEnd' => 'Return vouchers to the pool when an order is cancelled or deleted',
    'SHOP_MODULE_blPaymentBaseUseIframe'       => 'Use iframe instead of checkout button',
    'SHOP_MODULE_blPaymentBaseAutoAssignSinglePayment'
        => 'Skip the payment step when only one payment method is available',
    'SHOP_MODULE_blPaymentBaseAutoAssignSingleShipping'
        => 'Skip the shipping-method selection when only one delivery set is available',
    'SHOP_MODULE_iPaymentBaseCleanupPeriod'
        => 'Cleanup period (days) — age at which an unfinished order is cleaned up',
    'SHOP_MODULE_iPaymentBaseStaleCheckoutMinutes'
        => 'Stale checkout timeout (minutes) — age at which an in-flight checkout is released',

    // Sprint 14 (MOL-10) — shared "Help": OXID contract states and their meaning (providers add their column).
    'PAYMENT_ADMIN_HELP' => 'Help',
    'PAYMENT_ADMIN_HELP_CLOSE' => 'Close',
    'PAYMENT_ADMIN_HELP_CONTRACT_STATES_INTRO' => 'The Payment tab of an order shows the OXID Contract Status: the state of the payment contract this shop keeps for the order, independent of the payment provider. This table explains each state.',
    'PAYMENT_ADMIN_HELP_COL_CONTRACT_STATE' => 'OXID Contract Status',
    'PAYMENT_ADMIN_HELP_COL_MEANING' => 'Meaning',
    'PAYMENT_ADMIN_HELP_NONE' => 'none (shop-internal)',
    'PAYMENT_ADMIN_HELP_STATE_NOT_FINISHED' => 'The order row exists and the customer was handed over to the payment provider; nothing has been committed yet.',
    'PAYMENT_ADMIN_HELP_STATE_PENDING' => 'The customer has committed; the payment network has not confirmed yet.',
    'PAYMENT_ADMIN_HELP_STATE_AUTHORIZED' => 'Funds are reserved; the merchant still has to capture them.',
    'PAYMENT_ADMIN_HELP_STATE_READY_TO_COMMIT' => 'The money has been taken; the order is not committed in the shop yet.',
    'PAYMENT_ADMIN_HELP_STATE_COMMITTED_FULFILLED' => 'Shop-internal only: the order is committed (paid) and finally fulfilled.',
    'PAYMENT_ADMIN_HELP_STATE_CANCELLED' => 'Someone stopped the payment (customer, merchant or provider).',
    'PAYMENT_ADMIN_HELP_STATE_EXPIRED' => 'The payment window ran out before the customer completed the payment.',
    'PAYMENT_ADMIN_HELP_STATE_FAILED' => 'The payment attempt was rejected.',
];
