<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

class AgentNotificationService implements AgentNotificationServiceInterface
{
    public function __construct(
        private readonly AgentCallbackRegistryInterface $callbackRegistry,
        private readonly HttpClientInterface $httpClient,
        private readonly string $signingSecret = '',
        private readonly ?FileLoggerInterface $logger = null
    ) {
    }

    public function notify(string $contractId, AgentNotificationPayload $payload): AgentNotificationResult
    {
        $callbackUrl = $this->callbackRegistry->getCallbackUrl($contractId);
        if ($callbackUrl === null) {
            return AgentNotificationResult::noCallback();
        }

        $body = $payload->toJson();
        $signature = $this->generateSignature($body);

        $this->logger?->log('AgentNotification: sending', [
            'contractId' => $contractId,
            'url' => $callbackUrl,
            'eventType' => $payload->getEventType(),
        ]);

        return $this->sendNotification($callbackUrl, $body, $signature);
    }

    private function generateSignature(string $body): string
    {
        if ($this->signingSecret === '') {
            return '';
        }

        $timestamp = time();
        $signedPayload = "{$timestamp}.{$body}";

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $signedPayload, $this->signingSecret);
    }

    private function sendNotification(string $url, string $body, string $signature): AgentNotificationResult
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'OxidPaymentComponent/1.0',
        ];

        if ($signature !== '') {
            $headers['X-Webhook-Signature'] = $signature;
        }

        $response = $this->httpClient->post($url, $body, $headers, 10);

        if ($response->getError() !== null) {
            $this->logger?->log('AgentNotification: HTTP error', ['error' => $response->getError()]);
            return AgentNotificationResult::failed(0, $response->getError());
        }

        $httpCode = $response->getStatusCode();

        if ($response->isSuccessful()) {
            $this->logger?->log('AgentNotification: delivered', ['httpCode' => $httpCode]);
            return AgentNotificationResult::success($httpCode);
        }

        $this->logger?->log('AgentNotification: non-2xx response', [
            'httpCode' => $httpCode,
            'response' => substr($response->getBody(), 0, 200),
        ]);

        return AgentNotificationResult::failed($httpCode, "HTTP {$httpCode}");
    }
}
