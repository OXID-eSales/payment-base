<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

use DateTimeImmutable;

/**
 * Result of a payment capture operation.
 *
 * Sprint 3: Immutable value object returned by AbstractPaymentCaptureService.
 *
 * @since 1.0.0
 */
final readonly class CaptureResult
{
    /**
     * @param string $captureId Provider's capture/charge ID
     * @param float $amountCaptured Amount that was captured
     * @param string $currency Currency code (EUR, USD, etc.)
     * @param DateTimeImmutable $capturedAt When the capture occurred
     * @param array<string, mixed> $providerData Additional provider-specific data
     */
    public function __construct(
        public string $captureId,
        public float $amountCaptured,
        public string $currency,
        public DateTimeImmutable $capturedAt,
        public array $providerData = []
    ) {
    }
}
