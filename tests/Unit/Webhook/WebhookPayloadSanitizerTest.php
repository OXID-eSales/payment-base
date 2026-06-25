<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookPayloadSanitizer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 69a: H7 — Webhook payload PII redaction.
 */
#[CoversClass(\OxidEsales\PaymentBase\Webhook\WebhookPayloadSanitizer::class)]
#[Group('sprint-69a')]
#[Group('security')]
final class WebhookPayloadSanitizerTest extends TestCase
{
    private WebhookPayloadSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new WebhookPayloadSanitizer();
    }

    #[Test]
    public function sanitizerPreservesEventId(): void
    {
        $result = $this->sanitizer->sanitize(['id' => 'evt_123']);

        $this->assertSame('evt_123', $result['id']);
    }

    #[Test]
    public function sanitizerPreservesEventType(): void
    {
        $result = $this->sanitizer->sanitize(['type' => 'checkout.session.completed']);

        $this->assertSame('checkout.session.completed', $result['type']);
    }

    #[Test]
    public function sanitizerPreservesObjectId(): void
    {
        $result = $this->sanitizer->sanitize([
            'data' => ['object' => ['id' => 'cs_123']],
        ]);

        $this->assertSame('cs_123', $result['data']['object']['id']);
    }

    #[Test]
    public function sanitizerPreservesAmounts(): void
    {
        $result = $this->sanitizer->sanitize([
            'amount_total' => 2500,
            'amount_subtotal' => 2100,
        ]);

        $this->assertSame(2500, $result['amount_total']);
        $this->assertSame(2100, $result['amount_subtotal']);
    }

    #[Test]
    public function sanitizerPreservesCurrency(): void
    {
        $result = $this->sanitizer->sanitize(['currency' => 'eur']);

        $this->assertSame('eur', $result['currency']);
    }

    #[Test]
    public function sanitizerStripsCustomerEmail(): void
    {
        $result = $this->sanitizer->sanitize([
            'customer_details' => ['email' => 'john@example.com', 'name' => 'John'],
        ]);

        $this->assertSame('[REDACTED]', $result['customer_details']);
    }

    #[Test]
    public function sanitizerStripsCustomerName(): void
    {
        $result = $this->sanitizer->sanitize([
            'customer_name' => 'John Doe',
        ]);

        $this->assertSame('[REDACTED]', $result['customer_name']);
    }

    #[Test]
    public function sanitizerStripsShippingAddress(): void
    {
        $result = $this->sanitizer->sanitize([
            'shipping' => [
                'address' => ['line1' => '123 Main St', 'city' => 'Berlin'],
                'name' => 'John Doe',
            ],
        ]);

        $this->assertSame('[REDACTED]', $result['shipping']);
    }

    #[Test]
    public function sanitizerStripsNestedCardDetails(): void
    {
        $result = $this->sanitizer->sanitize([
            'payment_method_details' => [
                'card' => [
                    'last4' => '4242',
                    'exp_month' => 12,
                    'exp_year' => 2025,
                    'brand' => 'visa',
                ],
            ],
        ]);

        $this->assertSame('[REDACTED]', $result['payment_method_details']['card']['last4']);
        $this->assertSame('[REDACTED]', $result['payment_method_details']['card']['exp_month']);
        $this->assertSame('[REDACTED]', $result['payment_method_details']['card']['exp_year']);
        $this->assertSame('visa', $result['payment_method_details']['card']['brand']);
    }

    #[Test]
    public function sanitizerHandlesNestedObjects(): void
    {
        $result = $this->sanitizer->sanitize([
            'data' => [
                'object' => [
                    'id' => 'pi_123',
                    'billing_details' => [
                        'email' => 'secret@test.com',
                        'name' => 'Secret Person',
                    ],
                    'receipt_email' => 'receipt@test.com',
                ],
            ],
        ]);

        $this->assertSame('pi_123', $result['data']['object']['id']);
        $this->assertSame('[REDACTED]', $result['data']['object']['billing_details']);
        $this->assertSame('[REDACTED]', $result['data']['object']['receipt_email']);
    }

    #[Test]
    public function sanitizerHandlesEmptyPayload(): void
    {
        $result = $this->sanitizer->sanitize([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function sanitizerIsDeterministic(): void
    {
        $input = [
            'id' => 'evt_test',
            'customer_details' => ['email' => 'a@b.com'],
            'amount' => 1000,
        ];

        $result1 = $this->sanitizer->sanitize($input);
        $result2 = $this->sanitizer->sanitize($input);

        $this->assertSame($result1, $result2);
    }
}
