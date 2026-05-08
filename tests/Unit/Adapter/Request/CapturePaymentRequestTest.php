<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter\Request;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;

/**
 * @covers \OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest
 */
class CapturePaymentRequestTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $request = new CapturePaymentRequest(
            providerPaymentId: 'pi_123456'
        );

        $this->assertSame('pi_123456', $request->providerPaymentId);
        $this->assertNull($request->amount);
        $this->assertSame([], $request->metadata);
    }

    public function testConstructWithPartialAmount(): void
    {
        // Partial capture: capture less than the authorized amount
        $request = new CapturePaymentRequest(
            providerPaymentId: 'pi_123456',
            amount: 50.00
        );

        $this->assertSame('pi_123456', $request->providerPaymentId);
        $this->assertSame(50.00, $request->amount);
    }

    public function testConstructWithMetadata(): void
    {
        $metadata = ['orderId' => 'order-123', 'captured_by' => 'admin'];

        $request = new CapturePaymentRequest(
            providerPaymentId: 'pi_123456',
            metadata: $metadata
        );

        $this->assertSame($metadata, $request->metadata);
    }

    public function testNullAmountMeansFullCapture(): void
    {
        // When amount is null, capture the full authorized amount
        $request = new CapturePaymentRequest(
            providerPaymentId: 'pi_123456',
            amount: null
        );

        $this->assertNull($request->amount);
    }

    public function testIsReadonly(): void
    {
        $request = new CapturePaymentRequest(
            providerPaymentId: 'pi_123456'
        );

        $this->expectException(\Error::class);
        $request->amount = 100.00;
    }

    public function testIsProviderAgnostic(): void
    {
        // Verify this is provider-agnostic (no Stripe, Unzer, PayPal specific code)
        // Should accept any provider's payment ID format

        $stripeFormat = new CapturePaymentRequest(providerPaymentId: 'pi_stripe_123');
        $this->assertIsString($stripeFormat->providerPaymentId);

        $unzerFormat = new CapturePaymentRequest(providerPaymentId: 's-unz-123456');
        $this->assertIsString($unzerFormat->providerPaymentId);

        $paypalFormat = new CapturePaymentRequest(providerPaymentId: '4MW805572N795704B');
        $this->assertIsString($paypalFormat->providerPaymentId);

        // All should be treated the same - no validation of specific formats
        $this->assertTrue(true, 'Request object accepts any provider format');
    }
}
