<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter\Request;

use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest
 */
final class RefundPaymentRequestTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
        );

        $this->assertSame('pi_123456789', $request->providerPaymentId);
        $this->assertNull($request->amount);
        $this->assertNull($request->reason);
        $this->assertSame([], $request->metadata);
    }

    public function testConstructWithPartialRefund(): void
    {
        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
            amount: 25.50,
        );

        $this->assertSame('pi_123456789', $request->providerPaymentId);
        $this->assertSame(25.50, $request->amount);
    }

    public function testConstructWithReason(): void
    {
        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
            reason: 'Customer request',
        );

        $this->assertSame('Customer request', $request->reason);
    }

    public function testConstructWithAllParameters(): void
    {
        $metadata = [
            'refund_reason_code' => 'customer_request',
            'support_ticket' => 'TICKET-12345',
        ];

        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
            amount: 99.99,
            reason: 'Product returned',
            metadata: $metadata,
        );

        $this->assertSame('pi_123456789', $request->providerPaymentId);
        $this->assertSame(99.99, $request->amount);
        $this->assertSame('Product returned', $request->reason);
        $this->assertSame($metadata, $request->metadata);
    }

    public function testNullAmountIndicatesFullRefund(): void
    {
        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
        );

        // null amount should indicate full refund
        $this->assertNull($request->amount, 'Null amount should indicate full refund');
    }

    public function testIsReadonly(): void
    {
        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
        );

        $this->expectException(\Error::class);
        $request->amount = 50.00;
    }

    public function testReasonIsOptional(): void
    {
        $withoutReason = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
        );
        $this->assertNull($withoutReason->reason);

        $withReason = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
            reason: 'Duplicate charge',
        );
        $this->assertSame('Duplicate charge', $withReason->reason);
    }

    public function testIsProviderAgnostic(): void
    {
        // Verify this is provider-agnostic (no Stripe, Unzer, PayPal specific code)
        // Should accept any provider's payment ID format

        $stripeFormat = new RefundPaymentRequest(providerPaymentId: 'pi_stripe_123');
        $this->assertIsString($stripeFormat->providerPaymentId);

        $unzerFormat = new RefundPaymentRequest(providerPaymentId: 's-unz-123456');
        $this->assertIsString($unzerFormat->providerPaymentId);

        $paypalFormat = new RefundPaymentRequest(providerPaymentId: '4MW805572N795704B');
        $this->assertIsString($paypalFormat->providerPaymentId);

        // Reason should also be provider-agnostic (generic text)
        $genericReason = new RefundPaymentRequest(
            providerPaymentId: 'payment_123',
            reason: 'Any generic reason text',
        );
        $this->assertIsString($genericReason->reason);
    }

    public function testMetadataIsArray(): void
    {
        $metadata = ['key1' => 'value1', 'key2' => 'value2'];

        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
            metadata: $metadata,
        );

        $this->assertIsArray($request->metadata);
        $this->assertCount(2, $request->metadata);
    }

    // =========================================================================
    // Sprint 114.10a (§6.2): optional currency field — RED tests
    // =========================================================================

    /**
     * @test
     */
    public function currencyDefaultsToNullWhenNotProvided(): void
    {
        $request = new RefundPaymentRequest(providerPaymentId: 'pi_123456789');

        $this->assertNull($request->currency);
    }

    /**
     * @test
     */
    public function currencyCanBeSet(): void
    {
        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_123456789',
            amount: 1000.0,
            currency: 'JPY'
        );

        $this->assertSame('JPY', $request->currency);
    }
}
