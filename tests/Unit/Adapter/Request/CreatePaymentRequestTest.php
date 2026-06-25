<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter\Request;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest::class)]
class CreatePaymentRequestTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card'
        );

        $this->assertSame(99.99, $request->amount);
        $this->assertSame('EUR', $request->currency);
        $this->assertSame('order-123', $request->orderId);
        $this->assertSame('shop-1', $request->shopId);
        $this->assertSame('card', $request->paymentMethod);
        $this->assertFalse($request->directCapture);
        $this->assertNull($request->paymentMethodId);
        $this->assertNull($request->customerId);
    }

    public function testConstructWithAllParameters(): void
    {
        $metadata = ['key' => 'value'];
        $billingAddress = ['street' => '123 Main St', 'city' => 'Berlin'];
        $shippingAddress = ['street' => '456 Oak Ave', 'city' => 'Munich'];

        $request = new CreatePaymentRequest(
            amount: 150.00,
            currency: 'USD',
            orderId: 'order-456',
            shopId: 'shop-2',
            paymentMethod: 'paypal',
            directCapture: true,
            paymentMethodId: 'pm_123',
            customerId: 'cus_abc',
            returnUrl: 'https://shop.com/success',
            cancelUrl: 'https://shop.com/cancel',
            metadata: $metadata,
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress
        );

        $this->assertSame(150.00, $request->amount);
        $this->assertSame('USD', $request->currency);
        $this->assertTrue($request->directCapture);
        $this->assertSame('pm_123', $request->paymentMethodId);
        $this->assertSame('cus_abc', $request->customerId);
        $this->assertSame('https://shop.com/success', $request->returnUrl);
        $this->assertSame('https://shop.com/cancel', $request->cancelUrl);
        $this->assertSame($metadata, $request->metadata);
        $this->assertSame($billingAddress, $request->billingAddress);
        $this->assertSame($shippingAddress, $request->shippingAddress);
    }

    public function testIsReadonly(): void
    {
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card'
        );

        // Readonly class - properties cannot be modified after construction
        $this->expectException(\Error::class);
        $request->amount = 200.00;
    }

    public function testAmountInMajorUnits(): void
    {
        // Verify amounts are in major units (99.99 EUR, not 9999 cents)
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card'
        );

        $this->assertSame(99.99, $request->amount);
        $this->assertIsFloat($request->amount);
    }

    public function testCurrencyIsUppercase(): void
    {
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card'
        );

        $this->assertSame('EUR', $request->currency);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $request->currency);
    }

    public function testGenericPaymentMethodNames(): void
    {
        // Payment methods should be generic: 'card', 'paypal', 'sepa', not provider-specific
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card'
        );

        $this->assertContains($request->paymentMethod, ['card', 'paypal', 'sepa', 'sepa_debit', 'ideal', 'bancontact']);
    }

    public function testMetadataIsArray(): void
    {
        $metadata = ['orderId' => 'order-123', 'shopId' => 'shop-1'];

        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card',
            metadata: $metadata
        );

        $this->assertIsArray($request->metadata);
        $this->assertCount(2, $request->metadata);
        $this->assertSame('order-123', $request->metadata['orderId']);
    }

    public function testDirectCaptureFlag(): void
    {
        // directCapture = false means authorize only (two-step)
        $authOnly = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card',
            directCapture: false
        );
        $this->assertFalse($authOnly->directCapture);

        // directCapture = true means capture immediately (one-step)
        $directCapture = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: 'shop-1',
            paymentMethod: 'card',
            directCapture: true
        );
        $this->assertTrue($directCapture->directCapture);
    }
}
