<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem;

use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

interface EventDispatcherInterface
{
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void;

    public function removeListener(string $eventClass, callable $listener): void;

    public function dispatch(EventInterface $event): EventInterface;
}
