<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

/**
 * Why a payment is flagged "at risk": money or goods are exposed and only the merchant can decide what to do.
 *
 * @since 1.8.0
 */
enum AtRiskReason: string
{
    /** A manual-capture authorization ran out on an order that is committed (nothing was captured). */
    case AuthorizationExpired = 'authorization_expired';
    /** The provider denied a capture of a committed order. */
    case CaptureDenied = 'capture_denied';
    /** The provider reversed a capture (the money left the merchant). */
    case CaptureReversed = 'capture_reversed';
    /** The customer opened a dispute / chargeback. */
    case DisputeOpened = 'dispute_opened';
    /** Money arrived on a contract that cannot take it and the provider refused to give it back. */
    case LateMoneyNotReturned = 'late_money_not_returned';
}
