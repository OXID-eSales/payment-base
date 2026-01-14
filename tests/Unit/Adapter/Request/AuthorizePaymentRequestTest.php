<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Adapter\Request;

use OxidEsales\PaymentComponent\Adapter\Request\AuthorizePaymentRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Adapter\Request\AuthorizePaymentRequest
 */
final class AuthorizePaymentRequestTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $request = new AuthorizePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
        );

        $this->assertSame(99.99, $request->amount);
        $this->assertSame('EUR', $request->currency);
        $this->assertSame('order-123', $request->orderId);
        $this->assertSame('1', $request->shopId);
        $this->assertSame('card', $request->paymentMethod);
        $this->assertNull($request->paymentMethodId);
        $this->assertNull($request->customerId);
        $this->assertSame([], $request->metadata);
    }

    public function testConstructWithSavedPaymentMethod(): void
    {
        $request = new AuthorizePaymentRequest(
            amount: 150.00,
            currency: 'USD',
            orderId: 'order-456',
            shopId: '1',
            paymentMethod: 'card',
            paymentMethodId: 'pm_saved_123',
            customerId: 'cust_123',
        );

        $this->assertSame('pm_saved_123', $request->paymentMethodId);
        $this->assertSame('cust_123', $request->customerId);
    }

    public function testConstructWithAllParameters(): void
    {
        $metadata = ['order_type' => 'subscription'];
        $billingAddress = ['name' => 'John Doe', 'city' => 'Berlin'];
        $shippingAddress = ['name' => 'Jane Doe', 'city' => 'Munich'];

        $request = new AuthorizePaymentRequest(
            amount: 200.00,
            currency: 'GBP',
            orderId: 'order-789',
            shopId: '2',
            paymentMethod: 'card',
            paymentMethodId: 'pm_123',
            customerId: 'cust_456',
            returnUrl: 'https://shop.com/return',
            cancelUrl: 'https://shop.com/cancel',
            metadata: $metadata,
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
        );

        $this->assertSame(200.00, $request->amount);
        $this->assertSame('GBP', $request->currency);
        $this->assertSame('pm_123', $request->paymentMethodId);
        $this->assertSame('https://shop.com/return', $request->returnUrl);
        $this->assertSame('https://shop.com/cancel', $request->cancelUrl);
        $this->assertSame($metadata, $request->metadata);
        $this->assertSame($billingAddress, $request->billingAddress);
        $this->assertSame($shippingAddress, $request->shippingAddress);
    }

    public function testAmountIsInMajorUnits(): void
    {
        $request = new AuthorizePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
        );

        // Amount should be 99.99 EUR, NOT 9999 cents
        $this->assertSame(99.99, $request->amount);
        $this->assertIsFloat($request->amount);
    }

    public function testCurrencyIsString(): void
    {
        $request = new AuthorizePaymentRequest(
            amount: 50.00,
            currency: 'USD',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
        );

        // Currency should be ISO 4217 code (String, not enum or int)
        $this->assertIsString($request->currency);
        $this->assertSame('USD', $request->currency);
    }

    public function testIsReadonly(): void
    {
        $request = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
        );

        $this->expectException(\Error::class);
        $request->amount = 200.00;
    }

    public function testIsProviderAgnostic(): void
    {
        // Verify this is provider-agnostic (no Stripe, Unzer, PayPal specific code)

        // Test with generic payment method names
        $cardPayment = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'order-1',
            shopId: '1',
            paymentMethod: 'card',  // Generic: not 'stripe_card'
        );
        $this->assertSame('card', $cardPayment->paymentMethod);

        $sepaPayment = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'order-2',
            shopId: '1',
            paymentMethod: 'sepa_debit',  // Generic: not 'stripe_sepa'
        );
        $this->assertSame('sepa_debit', $sepaPayment->paymentMethod);

        // Should accept any provider's payment method ID format
        $stripeMethodId = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'order-3',
            shopId: '1',
            paymentMethod: 'card',
            paymentMethodId: 'pm_stripe_123',  // Stripe format
        );
        $this->assertIsString($stripeMethodId->paymentMethodId);

        $unzerMethodId = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'order-4',
            shopId: '1',
            paymentMethod: 'card',
            paymentMethodId: 's-crd-12345',  // Unzer format
        );
        $this->assertIsString($unzerMethodId->paymentMethodId);
    }

    public function testAddressFieldsAreOptionalArrays(): void
    {
        $request = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
        );

        $this->assertNull($request->billingAddress);
        $this->assertNull($request->shippingAddress);

        $requestWithAddresses = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
            billingAddress: ['city' => 'Berlin'],
            shippingAddress: ['city' => 'Munich'],
        );

        $this->assertIsArray($requestWithAddresses->billingAddress);
        $this->assertIsArray($requestWithAddresses->shippingAddress);
    }
}
