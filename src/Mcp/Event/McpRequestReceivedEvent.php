<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Event;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

class McpRequestReceivedEvent implements EventInterface
{
    public function __construct(
        private readonly EventContext $context
    ) {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }
}
