<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;

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
