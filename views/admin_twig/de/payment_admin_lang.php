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
    // VR-23 T1: payment-at-risk badge
    'PAYMENT_ADMIN_AT_RISK' => 'Zahlung gefährdet - Ihre Entscheidung ist erforderlich. Es wurde nichts automatisch storniert oder erstattet.',
    'PAYMENT_ADMIN_AT_RISK_AUTHORIZATION_EXPIRED' => 'Die Autorisierung ist abgelaufen, bevor die Zahlung eingezogen wurde.',
    'PAYMENT_ADMIN_AT_RISK_CAPTURE_DENIED' => 'Der Anbieter hat den Einzug abgelehnt.',
    'PAYMENT_ADMIN_AT_RISK_CAPTURE_REVERSED' => 'Der Anbieter hat den Einzug rückgängig gemacht.',
    'PAYMENT_ADMIN_AT_RISK_CHARGEBACK' => 'Die Bank des Kunden hat das Geld zurückgebucht (Rückbuchung).',
    'PAYMENT_ADMIN_AT_RISK_DISPUTE_OPENED' => 'Der Kunde hat einen Streitfall eröffnet.',
    'PAYMENT_ADMIN_AT_RISK_LATE_MONEY_NOT_RETURNED' => 'Geld ist auf einer stornierten Bestellung eingegangen und der Anbieter hat es nicht zurückgegeben.',

    // Moduleinstellungen — Gruppenüberschriften
    'SHOP_MODULE_GROUP_validation'             => 'Validierung',
    'SHOP_MODULE_GROUP_per_line_vat'           => 'Positionsbezogene USt.',
    'SHOP_MODULE_GROUP_iframe_checkout'        => 'Iframe-Checkout',
    'SHOP_MODULE_GROUP_checkout_flow'          => 'Checkout-Ablauf',
    'SHOP_MODULE_GROUP_cleanup'                => 'Bereinigung',
    'SHOP_MODULE_GROUP_headless'               => 'Headless-Checkout (GraphQL, Apps)',
    'SHOP_MODULE_GROUP_webhooks'               => 'Webhooks',
    'SHOP_MODULE_GROUP_return_leg'             => 'Rücksprung',

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
    'SHOP_MODULE_sPaymentBaseTrustedProxies'
        => 'Vertrauenswürdige Reverse-Proxys — Adressen oder CIDR-Bereiche, durch Komma oder Zeilenumbruch getrennt (z. B. die Bereiche von Cloudflare); leer = keine, die verbindende Adresse ist der Aufrufer',
    'SHOP_MODULE_sPaymentBaseClientIpHeader'
        => 'Header, in dem diese Proxys die Adresse des Aufrufers übergeben (z. B. CF-Connecting-IP; Standard X-Forwarded-For)',
    'SHOP_MODULE_iPaymentBaseReturnTokenTtlHours'
        => 'Gültigkeit des Rücksprung-Links (Stunden) — wie lange der Link vom Zahlungsanbieter zurück in den Shop funktioniert; Standard 24',
    'SHOP_MODULE_blPaymentBaseAcceptLegacyReturnTokens'
        => 'Rücksprung-Links aus der Zeit vor dem gemeinsamen Token weiter akzeptieren (Übergang für Kunden, die während des Updates im Checkout sind; danach ausschalten)',
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
