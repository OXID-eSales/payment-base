<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Default implementation of payment refund service.
 *
 * Sprint 3: Extends AbstractPaymentRefundService with default behavior.
 * - Validates FULFILLED state before refund
 * - Logs refund to transaction repository
 *
 * For providers needing different behavior,
 * create a provider-specific service extending AbstractPaymentRefundService.
 *
 * @see SPRINT-3-TICKET-13-capture-refund-operations.md
 * @since 1.0.0
 */
class PaymentRefundService extends AbstractPaymentRefundService
{
    // Uses all default behavior from AbstractPaymentRefundService:
    // - validateStateForRefund(): checks FULFILLED state
    // - afterRefund(): logs to transaction repository
}
