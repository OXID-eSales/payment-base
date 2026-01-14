<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;

/**
 * Manages event listeners and provides them to EventDispatcher.
 * Integrates with Symfony DI via tagged services.
 *
 * Sprint 11: Uses lazy initialization to prevent circular dependency.
 * Handlers are not instantiated until getListenersForEvent() is called.
 *
 * @since 1.0.0
 */
class EventListenerProvider implements EventListenerProviderInterface
{
    /** @var array<string, array<array{listener: callable, priority: int}>> */
    private array $listeners = [];

    /** @var iterable<HandlerInterface> */
    private iterable $handlers;

    private bool $initialized = false;

    /**
     * @param iterable<HandlerInterface> $handlers Handlers injected via DI (tagged services)
     */
    public function __construct(iterable $handlers = [])
    {
        // Store handlers without iterating - lazy initialization
        $this->handlers = $handlers;
    }

    public function getListenersForEvent(string $eventClass): array
    {
        // Lazy initialize handlers on first access
        $this->initialize();

        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        $listeners = $this->listeners[$eventClass];
        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return array_map(fn($item) => $item['listener'], $listeners);
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = [
            'listener' => $listener,
            'priority' => $priority,
        ];
    }

    /**
     * Lazy initialization of handlers.
     * Called on first access to prevent circular dependency during container build.
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        foreach ($this->handlers as $handler) {
            $this->registerHandler($handler);
        }
    }

    /**
     * Registers a handler by using its getHandledEventClass() method.
     * Priority is determined by getPriority() if implemented, otherwise 0.
     */
    private function registerHandler(HandlerInterface $handler): void
    {
        $eventClass = $handler::getHandledEventClass();
        $priority = method_exists($handler, 'getPriority') ? $handler->getPriority() : 0;
        $this->addListener($eventClass, [$handler, 'handle'], $priority);
    }
}
