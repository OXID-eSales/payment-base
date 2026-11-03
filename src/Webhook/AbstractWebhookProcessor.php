<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\Exception\WebhookSignatureException;
use Psr\Log\LoggerInterface;
use Throwable;

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
        protected readonly LoggerInterface $logger,
        protected readonly ThrowableSummaryInterface $throwableSummary
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
                'throwable' => $this->throwableSummary->fromThrowable($e),
                'remoteIp' => $request->remoteIp,
            ]);
            // VR-18 L1: the PSP gets a fixed text - an exception message is not for the caller.
            return WebhookResult::failure('signature_invalid', 'Webhook signature verification failed');
        }

        // Step 2+3: Atomic claim (replaces TOCTOU-vulnerable existsByEventId + save)
        if (!$this->logRepository->claimEvent($event->id, $this->getProviderName(), $event->type)) {
            $this->logger->info('Webhook already processed, skipping', [
                'eventId' => $event->id,
                'eventType' => $event->type,
            ]);
            return WebhookResult::skipped('Already processed: ' . $event->id);
        }

        // Step 4: Process event
        try {
            $result = $this->processEvent($event);
        } catch (Throwable $e) {
            // VR-19: any Throwable - a TypeError from a stale container must end the
            // delivery as `failed` too, or the PSP's retry finds it `claimed` forever.
            $this->logger->error('Webhook processing failed', [
                'eventId' => $event->id,
                'eventType' => $event->type,
                'throwable' => $this->throwableSummary->fromThrowable($e),
            ]);
            $this->logWebhookResult($event, 'failed', null, $this->throwableSummary->toLine($e));
            return WebhookResult::failure('processing_failed', 'Webhook processing failed');
        }

        // Step 5: Update log status
        $contractId = $this->getContractIdFromResult($result);
        $status = $result->isSuccess() ? 'processed' : 'failed';
        // A failed row keeps its reason and can be claimed again by the PSP's retry
        // ({@see WebhookLogRepositoryInterface::claimEvent()}).
        $this->logWebhookResult($event, $status, $contractId, $result->isSuccess() ? null : $result->error);

        return $result;
    }

    /**
     * Update webhook log status after processing.
     */
    protected function logWebhookResult(
        WebhookEvent $event,
        string $status,
        ?string $contractId,
        ?string $error = null
    ): void {
        $this->logRepository->updateStatus($event->id, $status, $error, $contractId);
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
