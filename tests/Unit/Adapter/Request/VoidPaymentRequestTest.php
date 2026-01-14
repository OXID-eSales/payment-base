<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Adapter\Request;

use OxidEsales\PaymentComponent\Adapter\Request\VoidPaymentRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Adapter\Request\VoidPaymentRequest
 */
final class VoidPaymentRequestTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $request = new VoidPaymentRequest(
            providerPaymentId: 'pi_123456789',
        );

        $this->assertSame('pi_123456789', $request->providerPaymentId);
        $this->assertNull($request->reason);
        $this->assertSame([], $request->metadata);
    }

    public function testConstructWithReason(): void
    {
        $request = new VoidPaymentRequest(
            providerPaymentId: 'pi_123456789',
            reason: 'Customer cancelled order',
        );

        $this->assertSame('pi_123456789', $request->providerPaymentId);
        $this->assertSame('Customer cancelled order', $request->reason);
    }

    public function testConstructWithAllParameters(): void
    {
        $metadata = [
            'cancellation_source' => 'customer',
            'cancelled_by' => 'user_123',
        ];

        $request = new VoidPaymentRequest(
            providerPaymentId: 'pi_123456789',
            reason: 'Order cancelled',
            metadata: $metadata,
        );

        $this->assertSame('pi_123456789', $request->providerPaymentId);
        $this->assertSame('Order cancelled', $request->reason);
        $this->assertSame($metadata, $request->metadata);
    }

    public function testReasonIsOptional(): void
    {
        $withoutReason = new VoidPaymentRequest(
            providerPaymentId: 'pi_123456789',
        );
        $this->assertNull($withoutReason->reason);

        $withReason = new VoidPaymentRequest(
            providerPaymentId: 'pi_123456789',
            reason: 'Fraudulent transaction',
        );
        $this->assertSame('Fraudulent transaction', $withReason->reason);
    }

    public function testIsReadonly(): void
    {
        $request = new VoidPaymentRequest(
            providerPaymentId: 'pi_123456789',
        );

        $this->expectException(\Error::class);
        $request->reason = 'Modified reason';
    }

    public function testMetadataIsArray(): void
    {
        $metadata = ['key1' => 'value1', 'key2' => 'value2'];

        $request = new VoidPaymentRequest(
            providerPaymentId: 'pi_123456789',
            metadata: $metadata,
        );

        $this->assertIsArray($request->metadata);
        $this->assertCount(2, $request->metadata);
    }

    public function testIsProviderAgnostic(): void
    {
        // Verify this is provider-agnostic (no Stripe, Unzer, PayPal specific code)
        // Should accept any provider's payment ID format

        $stripeFormat = new VoidPaymentRequest(providerPaymentId: 'pi_stripe_123');
        $this->assertIsString($stripeFormat->providerPaymentId);

        $unzerFormat = new VoidPaymentRequest(providerPaymentId: 's-unz-123456');
        $this->assertIsString($unzerFormat->providerPaymentId);

        $paypalFormat = new VoidPaymentRequest(providerPaymentId: '4MW805572N795704B');
        $this->assertIsString($paypalFormat->providerPaymentId);

        // Reason should be provider-agnostic (generic text)
        $genericReason = new VoidPaymentRequest(
            providerPaymentId: 'payment_123',
            reason: 'Generic void reason',
        );
        $this->assertIsString($genericReason->reason);
    }

    public function testProviderPaymentIdIsRequired(): void
    {
        $request = new VoidPaymentRequest(
            providerPaymentId: 'required_id_123',
        );

        $this->assertNotEmpty($request->providerPaymentId);
        $this->assertIsString($request->providerPaymentId);
    }
}
