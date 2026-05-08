<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Default implementation of payment capture service.
 *
 * Sprint 3: Extends AbstractPaymentCaptureService with default behavior.
 * - Validates COMMITTED state before capture
 * - Fulfills contract after capture
 *
 * For providers needing different behavior (e.g., Stripe's AUTHORIZED state),
 * create a provider-specific service extending AbstractPaymentCaptureService.
 *
 * @see SPRINT-3-TICKET-13-capture-refund-operations.md
 * @since 1.0.0
 */
class PaymentCaptureService extends AbstractPaymentCaptureService
{
    // Uses all default behavior from AbstractPaymentCaptureService:
    // - validateStateForCapture(): checks COMMITTED state
    // - afterCapture(): fulfills contract
}
