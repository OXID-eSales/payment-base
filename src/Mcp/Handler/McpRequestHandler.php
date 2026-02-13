<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\PaymentComponent\Mcp\Event\McpRequestReceivedEvent;
use OxidEsales\PaymentComponent\Mcp\McpServerInterface;

class McpRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly McpServerInterface $mcpServer
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return McpRequestReceivedEvent::class;
    }

    public function handle(object $event): void
    {
        /** @var McpRequestReceivedEvent $event */
        $context = $event->getContext();
        $rawJsonRpc = $context->get('rawJsonRpc');
        $agentContext = $context->get('agentContext');

        if (!is_string($rawJsonRpc) || !$agentContext instanceof AgentContextInterface) {
            return;
        }

        $response = $this->mcpServer->handleJsonRpc($rawJsonRpc, $agentContext);

        $context->set('mcpResponse', $response);
    }
}
