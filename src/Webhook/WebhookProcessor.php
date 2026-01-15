<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use Psr\Log\LoggerInterface;

class WebhookProcessor implements WebhookProcessorInterface
{
    /**
     * @param string $providerName Provider identifier (e.g., 'stripe', 'paypal')
     */
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly WebhookIdempotencyCheckerInterface $idempotencyChecker,
        private readonly WebhookLogRepositoryInterface $logRepository,
        private readonly LoggerInterface $logger,
        private readonly string $providerName = 'unknown'
    ) {
    }

    /**
     * @param array<string, mixed> $webhookData
     */
    public function process(array $webhookData): void
    {
        $processData = $this->prepareWebhookProcessing($webhookData);
        if ($processData === null) {
            return;
        }

        [$eventId, $eventType, $eventData, $contract] = $processData;

        $contractId = $contract->getId();
        if ($contractId === null) {
            $this->logger->error('Contract has no ID', ['eventId' => $eventId]);
            return;
        }

        $this->dispatchWebhookEvent($eventId, $eventType, $eventData, $contractId);
        $this->idempotencyChecker->markAsProcessed($eventId);
        $this->logWebhookEvent($eventId, $eventType, $contractId);
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function extractPaymentIntentId(array $eventData): ?string
    {
        if (!isset($eventData['object']) || !is_array($eventData['object'])) {
            return null;
        }

        /** @var array<string, mixed> $object */
        $object = $eventData['object'];
        $id = $object['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    private function logWebhookEvent(string $eventId, string $eventType, string $contractId): void
    {
        $log = new WebhookLog($eventId, new DateTimeImmutable(), 'processed');
        $log->setEventType($eventType);
        $log->setContractId($contractId);
        $this->logRepository->save($log);
    }

    /**
     * @param array<string, mixed> $webhookData
     * @return array{string, string, array<string, mixed>, PaymentContractInterface}|null
     */
    private function prepareWebhookProcessing(array $webhookData): ?array
    {
        $validated = $this->validateWebhookData($webhookData);
        if ($validated === null) {
            return null;
        }

        [$eventId, $eventType, $eventData] = $validated;

        if ($this->idempotencyChecker->isProcessed($eventId)) {
            $this->logger->info('Webhook already processed, skipping', ['eventId' => $eventId]);
            return null;
        }

        $contract = $this->findContractForWebhook($eventData, $eventType);
        if ($contract === null) {
            return null;
        }

        return [$eventId, $eventType, $eventData, $contract];
    }

    /**
     * @param array<string, mixed> $webhookData
     * @return array{string, string, array<string, mixed>}|null
     */
    private function validateWebhookData(array $webhookData): ?array
    {
        if (!isset($webhookData['id']) || !is_string($webhookData['id'])) {
            $this->logger->warning('Missing or invalid event ID in webhook data');
            return null;
        }

        if (!isset($webhookData['type']) || !is_string($webhookData['type'])) {
            $this->logger->warning('Missing or invalid event type in webhook data');
            return null;
        }

        if (!isset($webhookData['data']) || !is_array($webhookData['data'])) {
            $this->logger->warning('Missing or invalid event data in webhook data');
            return null;
        }

        /** @var array<string, mixed> $eventData */
        $eventData = $webhookData['data'];

        return [$webhookData['id'], $webhookData['type'], $eventData];
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function findContractForWebhook(array $eventData, string $eventType): ?PaymentContractInterface
    {
        $paymentIntentId = $this->extractPaymentIntentId($eventData);
        if (!$paymentIntentId) {
            $this->logger->warning('Cannot extract payment intent ID from webhook', ['eventType' => $eventType]);
            return null;
        }

        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if (!$contract) {
            $this->logger->warning('Contract not found for payment intent', ['paymentIntentId' => $paymentIntentId]);
            return null;
        }

        return $contract;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function dispatchWebhookEvent(
        string $eventId,
        string $eventType,
        array $eventData,
        string $contractId
    ): void {
        $context = new EventContext([
            'contractId' => $contractId,
            'webhookEventId' => $eventId,
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            $this->providerName,
            $eventType,
            $eventData,
            $eventId
        );

        $this->eventDispatcher->dispatch($event);
    }
}
