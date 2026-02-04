<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

use Exception;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\Exception\WebhookSignatureException;
use Psr\Log\LoggerInterface;

/**
 * Abstract webhook processor using Template Method pattern.
 *
 * Provides shared webhook processing logic:
 * - Idempotency checking (skip already processed events)
 * - Webhook logging (received, processed, failed)
 * - Error handling and result formatting
 *
 * Provider modules extend this class and implement:
 * - getProviderName(): Provider identifier for logging
 * - parseAndValidateRequest(): Parse payload and verify signature
 * - processEvent(): Route event to specific handlers
 * - getContractIdFromResult(): Extract contract ID for linking
 *
 * @since Sprint 5
 */
abstract class AbstractWebhookProcessor
{
    public function __construct(
        protected readonly WebhookLogRepositoryInterface $logRepository,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process a webhook request.
     *
     * Template Method that orchestrates the webhook processing flow:
     * 1. Parse and validate request (signature verification)
     * 2. Check idempotency (skip if already processed)
     * 3. Log webhook received
     * 4. Process event (provider-specific routing)
     * 5. Update log status
     *
     * @param WebhookRequest $request The incoming webhook request
     * @return WebhookResult Processing result
     */
    public function process(WebhookRequest $request): WebhookResult
    {
        // Step 1: Parse and validate request (throws WebhookSignatureException on failure)
        try {
            $event = $this->parseAndValidateRequest($request);
        } catch (WebhookSignatureException $e) {
            $this->logger->warning('Webhook signature verification failed', [
                'error' => $e->getMessage(),
                'remoteIp' => $request->remoteIp,
            ]);
            return WebhookResult::failure('signature_invalid', $e->getMessage());
        }

        // Step 2: Check idempotency
        if ($this->isAlreadyProcessed($event->id)) {
            $this->logger->info('Webhook already processed, skipping', [
                'eventId' => $event->id,
                'eventType' => $event->type,
            ]);
            return WebhookResult::skipped('Already processed');
        }

        // Step 3: Log webhook received
        $this->logWebhookReceived($event, $request);

        // Step 4: Process event
        try {
            $result = $this->processEvent($event);
        } catch (Exception $e) {
            $this->logger->error('Webhook processing failed', [
                'eventId' => $event->id,
                'eventType' => $event->type,
                'error' => $e->getMessage(),
            ]);
            $this->logWebhookResult($event, 'failed', null);
            return WebhookResult::failure('processing_failed', $e->getMessage());
        }

        // Step 5: Update log status
        $contractId = $this->getContractIdFromResult($result);
        $status = $result->isSuccess() ? 'processed' : 'failed';
        $this->logWebhookResult($event, $status, $contractId);

        return $result;
    }

    /**
     * Check if event was already processed (idempotency).
     */
    protected function isAlreadyProcessed(string $eventId): bool
    {
        return $this->logRepository->existsByEventId($eventId);
    }

    /**
     * Log webhook received.
     */
    protected function logWebhookReceived(WebhookEvent $event, WebhookRequest $request): void
    {
        $log = new WebhookLog(
            $event->id,
            $request->receivedAt,
            'received'
        );
        $log->setEventType($event->type);
        $log->setProvider($this->getProviderName());
        $log->setPayload($event->data);

        $this->logRepository->save($log);
    }

    /**
     * Update webhook log status after processing.
     */
    protected function logWebhookResult(WebhookEvent $event, string $status, ?string $contractId): void
    {
        $this->logRepository->updateStatus(
            $event->id,
            $status,
            null, // error message (null for success)
            $contractId
        );
    }

    /**
     * Get the provider name for logging.
     *
     * @return string Provider identifier (e.g., 'stripe', 'paypal')
     */
    abstract protected function getProviderName(): string;

    /**
     * Parse the webhook request and validate its signature.
     *
     * This method should:
     * 1. Parse the raw payload into a structured format
     * 2. Verify the webhook signature using provider-specific logic
     * 3. Return a WebhookEvent on success
     * 4. Throw WebhookSignatureException if signature is invalid
     *
     * @param WebhookRequest $request The incoming webhook request
     * @return WebhookEvent The parsed and verified event
     * @throws WebhookSignatureException If signature verification fails
     */
    abstract protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent;

    /**
     * Process the webhook event.
     *
     * This method should route the event to appropriate handlers based on
     * event type. Provider implementations typically use match/switch to
     * route different event types.
     *
     * @param WebhookEvent $event The verified webhook event
     * @return WebhookResult Processing result
     */
    abstract protected function processEvent(WebhookEvent $event): WebhookResult;

    /**
     * Extract contract ID from the processing result.
     *
     * Used to link the webhook log to the contract for traceability.
     * Return null if no contract was involved in processing.
     *
     * @param WebhookResult $result The processing result
     * @return string|null Contract ID if available
     */
    abstract protected function getContractIdFromResult(WebhookResult $result): ?string;
}
