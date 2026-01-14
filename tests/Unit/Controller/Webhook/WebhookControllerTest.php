<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Controller\Http;

use OxidEsales\PaymentComponent\Controller\Webhook\WebhookController;
use OxidEsales\PaymentComponent\Webhook\WebhookProcessorInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookSignatureVerifierInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\PaymentComponent\Controller\Webhook\WebhookController
 */
final class WebhookControllerTest extends TestCase
{
    private WebhookSignatureVerifierInterface $signatureVerifier;
    private WebhookProcessorInterface $processor;
    private LoggerInterface $logger;
    private WebhookController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signatureVerifier = $this->createMock(WebhookSignatureVerifierInterface::class);
        $this->processor = $this->createMock(WebhookProcessorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Use anonymous class to test abstract WebhookController
        $this->controller = new class (
            $this->signatureVerifier,
            $this->processor,
            $this->logger
        ) extends WebhookController {
        };
    }

    public function testHandlesValidWebhookRequest(): void
    {
        $payload = json_encode([
            'id' => 'evt_test_123',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123']],
        ]);
        $signature = 'valid_signature';

        $this->signatureVerifier->expects($this->once())
            ->method('verify')
            ->with($payload, $signature)
            ->willReturn(true);

        $this->signatureVerifier->expects($this->once())
            ->method('parseEvent')
            ->with($payload, $signature)
            ->willReturn(json_decode($payload, true));

        $this->processor->expects($this->once())
            ->method('process')
            ->with(json_decode($payload, true));

        $result = $this->controller->handleWebhook($payload, $signature);

        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('success', $result['body']['status']);
        $this->assertTrue($result['body']['received']);
    }

    public function testRejectsInvalidSignature(): void
    {
        $payload = '{"id": "evt_test"}';
        $signature = 'invalid_signature';

        $this->signatureVerifier->expects($this->once())
            ->method('verify')
            ->with($payload, $signature)
            ->willReturn(false);

        $this->signatureVerifier->expects($this->never())
            ->method('parseEvent');

        $this->processor->expects($this->never())
            ->method('process');

        $result = $this->controller->handleWebhook($payload, $signature);

        $this->assertSame(401, $result['statusCode']);
        $this->assertArrayHasKey('error', $result['body']);
        $this->assertStringContainsString('Invalid signature', $result['body']['error']);
    }

    public function testRejectsMissingSignatureHeader(): void
    {
        $payload = '{"id": "evt_test"}';
        $signature = '';

        $this->signatureVerifier->expects($this->never())
            ->method('verify');

        $result = $this->controller->handleWebhook($payload, $signature);

        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey('error', $result['body']);
        $this->assertStringContainsString('Missing signature', $result['body']['error']);
    }

    public function testRejectsInvalidJsonPayload(): void
    {
        $payload = 'invalid json{';
        $signature = 'valid_signature';

        $this->signatureVerifier->expects($this->once())
            ->method('verify')
            ->with($payload, $signature)
            ->willReturn(true);

        $this->signatureVerifier->expects($this->once())
            ->method('parseEvent')
            ->with($payload, $signature)
            ->willThrowException(new \JsonException('Invalid JSON'));

        $this->processor->expects($this->never())
            ->method('process');

        $result = $this->controller->handleWebhook($payload, $signature);

        $this->assertSame(400, $result['statusCode']);
        $this->assertArrayHasKey('error', $result['body']);
    }

    public function testReturnsSuccessAfterProcessing(): void
    {
        $payload = json_encode([
            'id' => 'evt_success',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123']],
        ]);
        $signature = 'valid_sig';

        $webhookData = json_decode($payload, true);

        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->signatureVerifier->method('parseEvent')->willReturn($webhookData);

        $this->processor->expects($this->once())
            ->method('process')
            ->with($webhookData);

        $result = $this->controller->handleWebhook($payload, $signature);

        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('success', $result['body']['status']);
    }

    public function testHandlesProcessingException(): void
    {
        $payload = json_encode(['id' => 'evt_error', 'type' => 'test']);
        $signature = 'valid_sig';

        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->signatureVerifier->method('parseEvent')->willReturn(json_decode($payload, true));

        $exception = new \RuntimeException('Processing failed');
        $this->processor->expects($this->once())
            ->method('process')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Webhook processing failed'),
                $this->callback(function ($context) use ($exception) {
                    return isset($context['error'])
                        && $context['error'] === $exception->getMessage();
                })
            );

        $result = $this->controller->handleWebhook($payload, $signature);

        $this->assertSame(500, $result['statusCode']);
        $this->assertArrayHasKey('error', $result['body']);
        $this->assertStringContainsString('Internal server error', $result['body']['error']);
    }
}
