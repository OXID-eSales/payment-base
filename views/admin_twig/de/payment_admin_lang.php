<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

$sLangName = 'Deutsch';

$aLang = [
    'charset'                     => 'UTF-8',
    'PAYMENT_ADMIN_TAB'           => 'Zahlung',
    'PAYMENT_ADMIN_NO_PROVIDER'   => 'Diese Bestellung wurde nicht über einen registrierten Online-Zahlungsanbieter abgewickelt. Manuelle Zahlungsverarbeitung.',
    'PAYMENT_ADMIN_CONTRACT_ID'   => 'Vertrags-ID',
    'PAYMENT_ADMIN_CONTRACT_STATE'=> 'Vertragsstatus',
    'PAYMENT_ADMIN_AMOUNT'        => 'Betrag',
    'PAYMENT_ADMIN_CAPTURED'      => 'Eingezogen',
    'PAYMENT_ADMIN_REFUNDED'      => 'Erstattet',
    'PAYMENT_ADMIN_PROVIDER_ORDER'=> 'Anbieter-Bestell-ID',
    'PAYMENT_ADMIN_ACTION_OK'     => 'Aktion erfolgreich abgeschlossen.',
    'PAYMENT_ADMIN_ACTION_FAILED' => 'Aktion fehlgeschlagen. Details im Shop-Log.',

    // Moduleinstellungen — Gruppenüberschriften
    'SHOP_MODULE_GROUP_validation'             => 'Validierung',
    'SHOP_MODULE_GROUP_per_line_vat'           => 'Positionsbezogene USt.',

    // Moduleinstellungen — Feldbeschriftungen
    'SHOP_MODULE_iValidationApiRatePerMinute'  => 'Validierungs-API Ratenlimit (Anfragen pro Minute)',
    'SHOP_MODULE_blPaymentBasePerLineVat'      => 'USt. pro Position berechnen (jede Position vor Summierung runden)',
];
