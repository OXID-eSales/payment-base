<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

use Psr\Log\LoggerInterface;

/**
 * Dispatcher for webhook events.
 *
 * Routes verified webhook events to registered handlers based on event type.
 * First matching handler wins - only one handler processes each event.
 *
 * @since Sprint 13
 */
class WebhookEventDispatcher implements WebhookEventDispatcherInterface
{
    /** @var array<WebhookEventHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function registerHandler(WebhookEventHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * @inheritDoc
     */
    public function dispatch(WebhookEvent $event): WebhookResult
    {
        $this->logger->info('Dispatching webhook event', [
            'event_id' => $event->id,
            'event_type' => $event->type,
        ]);

        foreach ($this->handlers as $handler) {
            if (!$handler->supports($event->type)) {
                continue;
            }

            return $this->executeHandler($handler, $event);
        }

        $this->logger->info('No handler found for event', [
            'event_id' => $event->id,
            'event_type' => $event->type,
        ]);

        return WebhookResult::skipped('No handler found for event type: ' . $event->type);
    }

    /**
     * Execute a handler with exception handling.
     */
    private function executeHandler(WebhookEventHandlerInterface $handler, WebhookEvent $event): WebhookResult
    {
        try {
            $result = $handler->handle($event);

            $this->logger->info('Handler completed', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'handler' => $handler::class,
                'success' => $result->isSuccess(),
                'action' => $result->action,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Handler exception', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'handler' => $handler::class,
                'exception' => $e->getMessage(),
            ]);

            return WebhookResult::failure('exception', $e->getMessage());
        }
    }
}
