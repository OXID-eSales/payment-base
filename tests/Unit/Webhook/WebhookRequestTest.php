<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\OxidEsales\PaymentBase\Webhook\WebhookRequest::class)]
#[Group('sprint-13')]
#[Group('webhook')]
final class WebhookRequestTest extends TestCase
{
    #[Test]
    public function canCreateFromRawData(): void
    {
        $payload = '{"id":"evt_123","type":"payment_intent.succeeded"}';
        $signature = 't=1234567890,v1=abc123';
        $remoteIp = '54.187.174.169';
        $receivedAt = new \DateTimeImmutable('2025-12-05 10:30:00');

        $request = new WebhookRequest($payload, $signature, $remoteIp, $receivedAt);

        $this->assertInstanceOf(WebhookRequest::class, $request);
    }

    #[Test]
    public function getPayloadReturnsRawString(): void
    {
        $payload = '{"id":"evt_123","type":"payment_intent.succeeded"}';
        $request = new WebhookRequest($payload, 't=123,v1=abc', '127.0.0.1', new \DateTimeImmutable());

        $this->assertSame($payload, $request->payload);
    }

    #[Test]
    public function getSignatureReturnsHeader(): void
    {
        $signature = 't=1234567890,v1=abc123def456';
        $request = new WebhookRequest('{}', $signature, '127.0.0.1', new \DateTimeImmutable());

        $this->assertSame($signature, $request->signature);
    }

    #[Test]
    public function getRemoteIpReturnsClientIp(): void
    {
        $remoteIp = '54.187.174.169';
        $request = new WebhookRequest('{}', 't=123,v1=abc', $remoteIp, new \DateTimeImmutable());

        $this->assertSame($remoteIp, $request->remoteIp);
    }

    #[Test]
    public function getReceivedAtReturnsTimestamp(): void
    {
        $receivedAt = new \DateTimeImmutable('2025-12-05 10:30:00');
        $request = new WebhookRequest('{}', 't=123,v1=abc', '127.0.0.1', $receivedAt);

        $this->assertSame($receivedAt, $request->receivedAt);
    }

    #[Test]
    public function propertiesAreReadOnly(): void
    {
        $request = new WebhookRequest('{}', 't=123,v1=abc', '127.0.0.1', new \DateTimeImmutable());

        $reflection = new \ReflectionClass($request);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }

    #[Test]
    public function hasSignatureReturnsTrueWhenSignaturePresent(): void
    {
        $request = new WebhookRequest('{}', 't=123,v1=abc', '127.0.0.1', new \DateTimeImmutable());

        $this->assertTrue($request->hasSignature());
    }

    #[Test]
    public function hasSignatureReturnsFalseWhenSignatureEmpty(): void
    {
        $request = new WebhookRequest('{}', '', '127.0.0.1', new \DateTimeImmutable());

        $this->assertFalse($request->hasSignature());
    }
}
