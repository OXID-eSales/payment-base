<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Broker;

use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EventBroker implements EventBrokerInterface
{
    /** @var list<ProviderEventTranslatorInterface> */
    private array $translators;

    private LoggerInterface $logger;

    /**
     * @param iterable<ProviderEventTranslatorInterface> $translators
     */
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        iterable $translators = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->translators = [];
        foreach ($translators as $translator) {
            $this->translators[] = $translator;
        }
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatch(AbstractProviderRequestEvent $event): AbstractProviderRequestEvent
    {
        $providerName = $this->resolveProviderName($event);
        if ($providerName === null) {
            $this->logger->warning('EventBroker: no provider resolvable on request event', [
                'event' => $event::class,
            ]);
            return $event;
        }

        $translator = $this->findTranslator($providerName);
        if ($translator === null) {
            $this->logger->warning('EventBroker: no translator for provider', [
                'providerName' => $providerName,
                'event' => $event::class,
            ]);
            return $event;
        }

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
}
