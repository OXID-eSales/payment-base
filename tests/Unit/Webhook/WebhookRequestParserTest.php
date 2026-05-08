<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookRequestParser;
use OxidEsales\PaymentBase\Webhook\WebhookRequestParserInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentBase\Webhook\WebhookRequestParser
 * @group sprint-13
 * @group webhook
 */
final class WebhookRequestParserTest extends TestCase
{
    private WebhookRequestParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new WebhookRequestParser();
    }

    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(WebhookRequestParserInterface::class, $this->parser);
    }

    /**
     * @test
     */
    public function parsesValidJsonPayload(): void
    {
        $payload = '{"id":"evt_123","type":"payment_intent.succeeded"}';
        $headers = ['Stripe-Signature' => 't=123,v1=abc'];

        $request = $this->parser->parse($payload, $headers, '127.0.0.1');

        $this->assertInstanceOf(WebhookRequest::class, $request);
        $this->assertSame($payload, $request->payload);
    }

    /**
     * @test
     */
    public function throwsOnEmptyPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty payload');

        $this->parser->parse('', [], '127.0.0.1');
    }

    /**
     * @test
     */
    public function extractsSignatureFromHeaders(): void
    {
        $signature = 't=1234567890,v1=abc123def456';
        $headers = ['Stripe-Signature' => $signature];

        $request = $this->parser->parse('{"id":"evt_123"}', $headers, '127.0.0.1');

        $this->assertSame($signature, $request->signature);
    }

    /**
     * @test
     */
    public function extractsSignatureFromLowercaseHeader(): void
    {
        $signature = 't=1234567890,v1=abc123def456';
        $headers = ['stripe-signature' => $signature];

        $request = $this->parser->parse('{"id":"evt_123"}', $headers, '127.0.0.1');

        $this->assertSame($signature, $request->signature);
    }

    /**
     * @test
     */
    public function returnsEmptySignatureWhenHeaderMissing(): void
    {
        $request = $this->parser->parse('{"id":"evt_123"}', [], '127.0.0.1');

        $this->assertSame('', $request->signature);
    }

    /**
     * @test
     */
    public function extractsRemoteIp(): void
    {
        $remoteIp = '54.187.174.169';

        $request = $this->parser->parse('{"id":"evt_123"}', [], $remoteIp);

        $this->assertSame($remoteIp, $request->remoteIp);
    }

    /**
     * @test
     */
    public function setsReceivedAtToCurrentTime(): void
    {
        $before = new \DateTimeImmutable();

        $request = $this->parser->parse('{"id":"evt_123"}', [], '127.0.0.1');

        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $request->receivedAt);
        $this->assertLessThanOrEqual($after, $request->receivedAt);
    }

    /**
     * @test
     */
    public function handlesHttpPrefixedHeaders(): void
    {
        $signature = 't=123,v1=abc';
        $headers = ['HTTP_STRIPE_SIGNATURE' => $signature];

        $request = $this->parser->parse('{"id":"evt_123"}', $headers, '127.0.0.1');

        $this->assertSame($signature, $request->signature);
    }

    /**
     * @test
     */
    public function preservesRawPayloadExactly(): void
    {
        $payload = '{"id":"evt_123","data":{"object":{"id":"pi_456"}}}';

        $request = $this->parser->parse($payload, [], '127.0.0.1');

        $this->assertSame($payload, $request->payload);
    }
}
