<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

readonly class ProtectedResourceMetadata
{
    /**
     * @param string $resource Resource identifier (MCP server URL)
     * @param array<string> $authorizationServers Authorization server URLs
     * @param array<string> $scopesSupported Supported OAuth scopes
     * @param array<string> $bearerMethodsSupported How tokens are passed
     */
    public function __construct(
        private string $resource,
        private array $authorizationServers,
        private array $scopesSupported = ['mcp:tools', 'mcp:resources'],
        private array $bearerMethodsSupported = ['header']
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'authorization_servers' => $this->authorizationServers,
            'scopes_supported' => $this->scopesSupported,
            'bearer_methods_supported' => $this->bearerMethodsSupported,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
