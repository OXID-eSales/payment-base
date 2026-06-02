<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Broker;

use LogicException;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EventBroker implements EventBrokerInterface
{
    /** @var list<ProviderEventTranslatorInterface> */
    private array $translators;

    private LoggerInterface $logger;

    private ProviderEventResolverInterface $resolver;

    /**
     * @param iterable<ProviderEventTranslatorInterface> $translators
     */
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        iterable $translators = [],
        ?LoggerInterface $logger = null,
        ?ProviderEventResolverInterface $resolver = null,
    ) {
        $this->translators = [];
        foreach ($translators as $translator) {
            $this->translators[] = $translator;
        }
        $this->logger = $logger ?? new NullLogger();
        $this->resolver = $resolver ?? new ConventionProviderEventResolver();
    }

    public function dispatch(AbstractProviderRequestEvent $event): AbstractProviderRequestEvent
    {
        $providerName = $this->resolveProviderName($event);
        if ($providerName === null) {
            // STRP-AUTOCAP-REFUND: silent-skip was the bug; loud error keeps
            // misconfiguration visible.
            $this->logger->error('EventBroker: no provider resolvable on request event', [
                'event' => $event::class,
            ]);
            return $event;
        }

        // Explicit translator path (existing behaviour). A provider that
        // ships a {@see ProviderEventTranslatorInterface} always wins over
        // the convention.
        $translator = $this->findTranslator($providerName);
        if ($translator !== null) {
            $translated = $translator->translate($event);
            if ($translated === null) {
                $this->logger->info('EventBroker: translator returned null', [
                    'providerName' => $providerName,
                    'event' => $event::class,
                ]);
                return $event;
            }
            $this->dispatcher->dispatch($translated);
            return $event;
        }

        // Convention-based fallback. No translator registered → resolve the
        // provider-specific event class by naming convention and dispatch it
        // directly through the standard event dispatcher. This is the
        // payment-module-agnostic default — a new provider that follows the
        // convention drops in with zero changes to payment-base.
        if ($this->dispatchByConvention($providerName, $event)) {
            return $event;
        }

        $this->logger->error('EventBroker: no translator and no convention class for provider', [
            'providerName' => $providerName,
            'event' => $event::class,
        ]);
        return $event;
    }

    private function resolveProviderName(AbstractProviderRequestEvent $event): ?string
    {
        $context = $event->getContext();
        $fromCtx = $context->get('providerName');
        if (is_string($fromCtx) && $fromCtx !== '') {
            return $fromCtx;
        }
        $contract = $context->getContract();
        $fromContract = $contract?->getProvider();
        return is_string($fromContract) && $fromContract !== '' ? $fromContract : null;
    }

    private function findTranslator(string $providerName): ?ProviderEventTranslatorInterface
    {
        foreach ($this->translators as $translator) {
            if ($translator->supports($providerName)) {
                return $translator;
            }
        }
        return null;
    }

    /**
     * Convention-based dispatch. Returns true when a provider-specific event
     * class was found and dispatched; false when no convention class exists
     * for this provider (caller logs + skips).
     */
    private function dispatchByConvention(
        string $providerName,
        AbstractProviderRequestEvent $event,
    ): bool {
        $baseName = self::baseNameFor($event);
        $fqcn = $this->resolver->resolveClassName($providerName, $baseName);

        if (!class_exists($fqcn)) {
            return false;
        }

        $translated = new $fqcn(
            $event->getContext(),
            $event->getAmount(),
            $event->getReason(),
        );
        if (!$translated instanceof EventInterface) {
            // Defence in depth — a class that follows the convention but doesn't
            // implement EventInterface is a misconfigured provider module. Log
            // loudly and skip rather than dispatching a non-event object.
            $this->logger->error('EventBroker: convention-resolved class does not implement EventInterface', [
                'providerName' => $providerName,
                'fqcn' => $fqcn,
            ]);
            return false;
        }
        $this->dispatcher->dispatch($translated);
        return true;
    }

    /**
     * Map generic request event class → provider-event base name suffix.
     * Each concrete request event in payment-base maps to exactly one
     * provider-event base name following the convention.
     */
    private static function baseNameFor(AbstractProviderRequestEvent $event): string
    {
        return match (true) {
            $event instanceof RefundRequestedEvent              => 'RefundRequestEvent',
            $event instanceof CaptureRequestedEvent             => 'CaptureRequestEvent',
            $event instanceof CancelAuthorizationRequestedEvent => 'CancelAuthorizationRequestEvent',
            default => throw new LogicException(
                'No convention base name registered for event class: ' . $event::class
            ),
        };
    }
}
