<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Ucp;

class UcpCapabilityNegotiationService
{
    /**
     * @param array<UcpCapability> $businessCapabilities
     * @param array<array{name: string, version: string}> $agentCapabilities
     * @return array<UcpCapability>
     */
    public function negotiate(array $businessCapabilities, array $agentCapabilities): array
    {
        $agentNames = array_column($agentCapabilities, 'name');
        $agentMap = array_combine($agentNames, $agentCapabilities);

        $negotiated = [];
        foreach ($businessCapabilities as $capability) {
            if (isset($agentMap[$capability->getName()])) {
                $negotiated[] = $capability;
            }
        }

        return $negotiated;
    }
}
