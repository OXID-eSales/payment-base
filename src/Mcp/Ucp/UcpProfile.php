<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

class UcpProfile implements UcpProfileInterface
{
    private const UCP_VERSION = '2026-01-11';

    /**
     * @param string $restEndpoint UCP REST endpoint URL
     * @param array<UcpCapability> $capabilities Supported capabilities
     * @param array<array{id: string, spec: string, version: string}> $paymentHandlers
     */
    public function __construct(
        private readonly string $restEndpoint,
        private readonly array $capabilities,
        private readonly array $paymentHandlers = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'ucp_version' => self::UCP_VERSION,
            'services' => [
                'dev.ucp.shopping' => [
                    'rest' => [
                        'endpoint' => $this->restEndpoint,
                    ],
                ],
            ],
            'capabilities' => array_map(
                fn(UcpCapability $cap) => $cap->toArray(),
                $this->capabilities
            ),
            'payment' => [
                'handlers' => $this->paymentHandlers,
            ],
        ];
    }

    public function getCapabilities(): array
    {
        return $this->capabilities;
    }
}
