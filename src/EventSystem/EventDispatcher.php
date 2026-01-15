<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem;

use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;

class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<array{listener: callable, priority: int}>> */
    private array $listeners = [];
    private ?EventListenerProviderInterface $listenerProvider;

    public function __construct(?EventListenerProviderInterface $listenerProvider = null)
    {
        $this->listenerProvider = $listenerProvider;
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

    public function removeListener(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        /** @var array<array{listener: callable, priority: int}> $eventListeners */
        $eventListeners = $this->listeners[$eventClass];
        $this->listeners[$eventClass] = array_filter(
            $eventListeners,
            static fn(array $item): bool => $item['listener'] !== $listener
        );
    }

    public function dispatch(EventInterface $event): EventInterface
    {
        $eventClass = get_class($event);

        // Get listeners from provider first (DI-registered handlers)
        /** @var array<callable> $listeners */
        $listeners = $this->listenerProvider
            ? $this->listenerProvider->getListenersForEvent($eventClass)
            : [];

        // Merge with locally added listeners
        if (isset($this->listeners[$eventClass])) {
            $localListeners = $this->getSortedListeners($eventClass);
            $listeners = array_merge($listeners, $localListeners);
        }

        foreach ($listeners as $listener) {
            if ($this->isStoppableEvent($event) && $this->isPropagationStopped($event)) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    /**
     * @return array<callable>
     */
    private function getSortedListeners(string $eventClass): array
    {
        /** @var array<array{listener: callable, priority: int}> $listeners */
        $listeners = $this->listeners[$eventClass];

        usort($listeners, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_map(static fn(array $item): callable => $item['listener'], $listeners);
    }

    private function isStoppableEvent(EventInterface $event): bool
    {
        return method_exists($event, 'isPropagationStopped');
    }

    private function isPropagationStopped(EventInterface $event): bool
    {
        /** @phpstan-ignore-next-line */
        return $event->isPropagationStopped();
    }
}
