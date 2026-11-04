<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter\Exception;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException::class)]
final class PaymentAdapterExceptionTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $exception = new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: 'card_declined',
            message: 'Card was declined',
        );

        $this->assertSame('stripe', $exception->getProviderName());
        $this->assertSame('card_declined', $exception->getErrorCode());
        $this->assertSame('Card was declined', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertSame([], $exception->getContext());
    }

    public function testConstructWithAllParameters(): void
    {
        $previous = new \RuntimeException('Original error');
        $context = ['attempt' => 1, 'payment_id' => 'pi_123'];

        $exception = new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: 'api_error',
            message: 'API request failed',
            code: 500,
            previous: $previous,
            context: $context,
        );

        $this->assertSame('stripe', $exception->getProviderName());
        $this->assertSame('api_error', $exception->getErrorCode());
        $this->assertSame('API request failed', $exception->getMessage());
        $this->assertSame(500, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame($context, $exception->getContext());
    }

    public function testIsProviderAgnostic(): void
    {
        // Verify exception works with any provider name (not just Stripe)

        $stripeException = new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: 'payment_failed',
        );
        $this->assertSame('stripe', $stripeException->getProviderName());

        $unzerException = new PaymentAdapterException(
            providerName: 'unzer',
            errorCode: 'payment_failed',
        );
        $this->assertSame('unzer', $unzerException->getProviderName());

        $paypalException = new PaymentAdapterException(
            providerName: 'paypal',
            errorCode: 'payment_failed',
        );
        $this->assertSame('paypal', $paypalException->getProviderName());
    }

    public function testErrorCodeIsProviderAgnostic(): void
    {
        // Error codes should be normalized (not provider-specific)

        $exception = new PaymentAdapterException(
            providerName: 'any_provider',
            errorCode: 'card_declined',  // Generic code, not 'stripe_card_declined'
        );

        $this->assertSame('card_declined', $exception->getErrorCode());
        $this->assertStringNotContainsString('stripe_', $exception->getErrorCode());
        $this->assertStringNotContainsString('unzer_', $exception->getErrorCode());
    }

    public function testContextStoresAdditionalInformation(): void
    {
        $context = [
            'payment_id' => 'pi_123',
            'attempt_number' => 2,
            'timestamp' => '2025-10-31T10:00:00Z',
        ];

        $exception = new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: 'api_error',
            context: $context,
        );

        $this->assertSame($context, $exception->getContext());
        $this->assertArrayHasKey('payment_id', $exception->getContext());
    }

    public function testExceptionIsThrowable(): void
    {
        $this->expectException(PaymentAdapterException::class);
        $this->expectExceptionMessage('Payment failed');

        throw new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: 'payment_failed',
            message: 'Payment failed',
        );
    }
}
