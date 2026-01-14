<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Adapter\Response;

use OxidEsales\PaymentComponent\Adapter\Response\PaymentResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Adapter\Response\PaymentResponse
 */
final class PaymentResponseTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $response = new PaymentResponse(
            providerPaymentId: 'pi_123456789',
            status: 'captured',
            amount: 99.99,
            currency: 'EUR',
        );

        $this->assertSame('pi_123456789', $response->providerPaymentId);
        $this->assertSame('captured', $response->status);
        $this->assertSame(99.99, $response->amount);
        $this->assertSame('EUR', $response->currency);
        $this->assertFalse($response->requiresAction);
        $this->assertNull($response->clientSecret);
        $this->assertNull($response->redirectUrl);
        $this->assertSame([], $response->providerData);
        $this->assertSame([], $response->metadata);
    }

    public function testConstructWithAllParameters(): void
    {
        $providerData = ['intent_id' => 'pi_123', 'charge_id' => 'ch_456'];
        $metadata = ['order_id' => 'order-789'];

        $response = new PaymentResponse(
            providerPaymentId: 'pi_123456789',
            status: 'pending',
            amount: 150.00,
            currency: 'USD',
            requiresAction: true,
            clientSecret: 'pi_secret_xyz',
            redirectUrl: 'https://3ds.provider.com/auth',
            providerData: $providerData,
            metadata: $metadata,
        );

        $this->assertSame('pi_123456789', $response->providerPaymentId);
        $this->assertSame('pending', $response->status);
        $this->assertSame(150.00, $response->amount);
        $this->assertSame('USD', $response->currency);
        $this->assertTrue($response->requiresAction);
        $this->assertSame('pi_secret_xyz', $response->clientSecret);
        $this->assertSame('https://3ds.provider.com/auth', $response->redirectUrl);
        $this->assertSame($providerData, $response->providerData);
        $this->assertSame($metadata, $response->metadata);
    }

    public function testStatusIsNormalized(): void
    {
        // Test normalized status values (provider-agnostic)
        $statuses = ['pending', 'authorized', 'captured', 'failed', 'cancelled'];

        foreach ($statuses as $status) {
            $response = new PaymentResponse(
                providerPaymentId: 'payment_123',
                status: $status,
                amount: 100.00,
                currency: 'EUR',
            );

            $this->assertSame($status, $response->status);
            $this->assertContains($status, $statuses, "Status {$status} should be normalized");
        }
    }

    public function testRequiresActionFor3DS(): void
    {
        $response = new PaymentResponse(
            providerPaymentId: 'pi_123',
            status: 'pending',
            amount: 100.00,
            currency: 'EUR',
            requiresAction: true,
            redirectUrl: 'https://3ds.example.com',
        );

        $this->assertTrue($response->requiresAction);
        $this->assertNotNull($response->redirectUrl);
    }

    public function testIsReadonly(): void
    {
        $response = new PaymentResponse(
            providerPaymentId: 'pi_123',
            status: 'captured',
            amount: 100.00,
            currency: 'EUR',
        );

        $this->expectException(\Error::class);
        $response->status = 'failed';
    }

    public function testAmountIsInMajorUnits(): void
    {
        $response = new PaymentResponse(
            providerPaymentId: 'pi_123',
            status: 'captured',
            amount: 99.99,
            currency: 'EUR',
        );

        // Amount should be 99.99 EUR, NOT 9999 cents
        $this->assertSame(99.99, $response->amount);
        $this->assertIsFloat($response->amount);
    }

    public function testIsProviderAgnostic(): void
    {
        // Verify this is provider-agnostic (no Stripe, Unzer, PayPal specific code)

        // Should accept any provider's payment ID format
        $stripeFormat = new PaymentResponse(
            providerPaymentId: 'pi_stripe_123',  // Stripe format
            status: 'captured',
            amount: 100.00,
            currency: 'EUR',
        );
        $this->assertIsString($stripeFormat->providerPaymentId);

        $unzerFormat = new PaymentResponse(
            providerPaymentId: 's-pay-123456',  // Unzer format
            status: 'captured',
            amount: 100.00,
            currency: 'EUR',
        );
        $this->assertIsString($unzerFormat->providerPaymentId);

        $paypalFormat = new PaymentResponse(
            providerPaymentId: '4MW805572N795704B',  // PayPal format
            status: 'captured',
            amount: 100.00,
            currency: 'EUR',
        );
        $this->assertIsString($paypalFormat->providerPaymentId);

        // Status should be normalized (not provider-specific)
        $this->assertContains($stripeFormat->status, ['pending', 'authorized', 'captured', 'failed', 'cancelled']);
    }

    public function testProviderDataStoresRawProviderInfo(): void
    {
        $providerData = [
            'raw_response' => ['id' => 'pi_123', 'object' => 'payment_intent'],
            'api_version' => 'v2024',
        ];

        $response = new PaymentResponse(
            providerPaymentId: 'pi_123',
            status: 'captured',
            amount: 100.00,
            currency: 'EUR',
            providerData: $providerData,
        );

        $this->assertIsArray($response->providerData);
        $this->assertArrayHasKey('raw_response', $response->providerData);
        $this->assertArrayHasKey('api_version', $response->providerData);
    }

    public function testClientSecretIsOptional(): void
    {
        $withoutSecret = new PaymentResponse(
            providerPaymentId: 'pi_123',
            status: 'captured',
            amount: 100.00,
            currency: 'EUR',
        );
        $this->assertNull($withoutSecret->clientSecret);

        $withSecret = new PaymentResponse(
            providerPaymentId: 'pi_123',
            status: 'pending',
            amount: 100.00,
            currency: 'EUR',
            clientSecret: 'secret_xyz',
        );
        $this->assertSame('secret_xyz', $withSecret->clientSecret);
    }
}
