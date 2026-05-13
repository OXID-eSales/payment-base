<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

use OxidEsales\PaymentBase\Admin\PaymentAdminController;
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
    'extend'      => [],
    'controllers' => [
        // OXID admin menu.xml tab-resolver needs the cl=> class map here
        // to render the Payment tab's `<a href>`. services.yaml DI is kept
        // but no longer uses `oxid.view_controller` tag — the controller
        // fetches its own dependencies via ContainerFactory inside render()
        // to avoid "Controller namespace duplication" on activation.
        'PaymentAdmin' => PaymentAdminController::class,
    ],
    'templates'   => [
        '@oe_payment_base/admin/payment_admin_tab' => 'views/twig/admin/payment_admin_tab.html.twig',
    ],
    'events'      => [
        'onActivate'   => ModuleLifecycle::class . '::onActivate',
        'onDeactivate' => ModuleLifecycle::class . '::onDeactivate',
    ],
    'settings'    => [],
];
