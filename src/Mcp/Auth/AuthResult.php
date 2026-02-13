<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;

readonly class AuthResult
{
    private function __construct(
        private bool $authenticated,
        private ?AgentContextInterface $agentContext,
        private ?string $errorMessage
    ) {
    }

    public static function success(AgentContextInterface $agentContext): self
    {
        return new self(true, $agentContext, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getAgentContext(): AgentContextInterface
    {
        if ($this->agentContext === null) {
            throw new \LogicException('Cannot get agent context from failed auth result');
        }
        return $this->agentContext;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
