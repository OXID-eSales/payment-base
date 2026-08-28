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
    'PAYMENT_ADMIN_CONTRACT_STATE'=> 'Contract state',
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
    'SHOP_MODULE_blPaymentBaseUseIframe'       => 'Use iframe instead of checkout button',
    'SHOP_MODULE_blPaymentBaseAutoAssignSinglePayment'
        => 'Skip the payment step when only one payment method is available',
    'SHOP_MODULE_blPaymentBaseAutoAssignSingleShipping'
        => 'Skip the shipping-method selection when only one delivery set is available',
    'SHOP_MODULE_iPaymentBaseCleanupPeriod'
        => 'Cleanup period (days) — age at which an unfinished order is cleaned up',
];
