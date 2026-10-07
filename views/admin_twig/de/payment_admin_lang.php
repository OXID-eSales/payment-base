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
    // Sprint 14 (MOL-10): der OXID-Vertragsstatus, so benannt; die Hilfe-Tabelle erklärt die Werte.
    'PAYMENT_ADMIN_CONTRACT_STATE'=> 'OXID-Vertragsstatus',
    'PAYMENT_ADMIN_AMOUNT'        => 'Betrag',
    'PAYMENT_ADMIN_CAPTURED'      => 'Eingezogen',
    'PAYMENT_ADMIN_REFUNDED'      => 'Erstattet',
    'PAYMENT_ADMIN_PROVIDER_ORDER'=> 'Anbieter-Bestell-ID',
    'PAYMENT_ADMIN_ACTION_OK'     => 'Aktion erfolgreich abgeschlossen.',
    'PAYMENT_ADMIN_ACTION_FAILED' => 'Aktion fehlgeschlagen. Details im Shop-Log.',

    // Moduleinstellungen — Gruppenüberschriften
    'SHOP_MODULE_GROUP_validation'             => 'Validierung',
    'SHOP_MODULE_GROUP_per_line_vat'           => 'Positionsbezogene USt.',
    'SHOP_MODULE_GROUP_iframe_checkout'        => 'Iframe-Checkout',
    'SHOP_MODULE_GROUP_checkout_flow'          => 'Checkout-Ablauf',
    'SHOP_MODULE_GROUP_cleanup'                => 'Bereinigung',
    'SHOP_MODULE_GROUP_headless'               => 'Headless-Checkout (GraphQL, Apps)',

    // Moduleinstellungen — Feldbeschriftungen
    'SHOP_MODULE_iValidationApiRatePerMinute'  => 'Validierungs-API Ratenlimit (Anfragen pro Minute)',
    'SHOP_MODULE_blPaymentBasePerLineVat'      => 'USt. pro Position berechnen (jede Position vor Summierung runden)',
    'SHOP_MODULE_blPaymentBaseReleaseVouchersOnOrderEnd' => 'Gutscheine bei Stornierung oder Löschung einer Bestellung wieder freigeben',
    'SHOP_MODULE_blPaymentBaseUseIframe'       => 'Iframe statt Checkout-Schaltfläche verwenden',
    'SHOP_MODULE_blPaymentBaseAutoAssignSinglePayment'
        => 'Zahlungsschritt überspringen, wenn nur eine Zahlungsart verfügbar ist',
    'SHOP_MODULE_blPaymentBaseAutoAssignSingleShipping'
        => 'Auswahl der Versandart überspringen, wenn nur eine Versandart verfügbar ist',
    'SHOP_MODULE_iPaymentBaseCleanupPeriod'
        => 'Bereinigungszeitraum (Tage) — Alter, ab dem eine unfertige Bestellung bereinigt wird',
    'SHOP_MODULE_iPaymentBaseStaleCheckoutMinutes'
        => 'Timeout für laufende Zahlvorgänge (Minuten) — Alter, ab dem ein Zahlvorgang freigegeben wird',
    'SHOP_MODULE_sPaymentBaseHeadlessReturnOrigins'
        => 'Erlaubte Rücksprung-Origins für Headless-Clients — durch Komma oder Zeilenumbruch getrennt, z. B. https://app.example.com; die Shop-URL ist immer erlaubt; leer = nur der Shop',

    // Sprint 14 (MOL-10) — gemeinsame „Hilfe“: OXID-Vertragsstatus und Bedeutung (Anbieter ergänzen ihre Spalte).
    'PAYMENT_ADMIN_HELP' => 'Hilfe',
    'PAYMENT_ADMIN_HELP_CLOSE' => 'Schließen',
    'PAYMENT_ADMIN_HELP_CONTRACT_STATES_INTRO' => 'Der Tab „Zahlung“ einer Bestellung zeigt den OXID-Vertragsstatus: den Zustand des Zahlungsvertrags, den dieser Shop zur Bestellung führt – unabhängig vom Zahlungsanbieter. Diese Tabelle erklärt jeden Status.',
    'PAYMENT_ADMIN_HELP_COL_CONTRACT_STATE' => 'OXID-Vertragsstatus',
    'PAYMENT_ADMIN_HELP_COL_MEANING' => 'Bedeutung',
    'PAYMENT_ADMIN_HELP_NONE' => 'keiner (nur shopintern)',
    'PAYMENT_ADMIN_HELP_STATE_NOT_FINISHED' => 'Die Bestellung ist angelegt und der Kunde wurde an den Zahlungsanbieter übergeben; noch nichts ist verbindlich.',
    'PAYMENT_ADMIN_HELP_STATE_PENDING' => 'Der Kunde hat sich festgelegt; das Zahlungsnetz hat noch nicht bestätigt.',
    'PAYMENT_ADMIN_HELP_STATE_AUTHORIZED' => 'Der Betrag ist reserviert; der Händler muss ihn noch einziehen (Capture).',
    'PAYMENT_ADMIN_HELP_STATE_READY_TO_COMMIT' => 'Das Geld ist eingezogen; die Bestellung ist im Shop noch nicht abgeschlossen.',
    'PAYMENT_ADMIN_HELP_STATE_COMMITTED_FULFILLED' => 'Nur shopintern: die Bestellung ist abgeschlossen (bezahlt) und schließlich erfüllt.',
    'PAYMENT_ADMIN_HELP_STATE_CANCELLED' => 'Jemand hat die Zahlung abgebrochen (Kunde, Händler oder Anbieter).',
    'PAYMENT_ADMIN_HELP_STATE_EXPIRED' => 'Das Zahlungsfenster ist abgelaufen, bevor der Kunde die Zahlung abgeschlossen hat.',
    'PAYMENT_ADMIN_HELP_STATE_FAILED' => 'Der Zahlungsversuch wurde abgelehnt.',
];
