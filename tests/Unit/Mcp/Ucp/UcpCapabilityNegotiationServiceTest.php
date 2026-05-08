<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Ucp;

use OxidEsales\PaymentBase\Mcp\Ucp\UcpCapability;
use OxidEsales\PaymentBase\Mcp\Ucp\UcpCapabilityNegotiationService;
use PHPUnit\Framework\TestCase;

class UcpCapabilityNegotiationServiceTest extends TestCase
{
    private UcpCapabilityNegotiationService $service;

    protected function setUp(): void
    {
        $this->service = new UcpCapabilityNegotiationService();
    }

    public function testMatchingCapabilitiesAreNegotiated(): void
    {
        $businessCaps = [
            new UcpCapability('checkout', '1.0'),
            new UcpCapability('catalog', '1.0'),
        ];

        $agentCaps = [
            ['name' => 'checkout', 'version' => '1.0'],
        ];

        $result = $this->service->negotiate($businessCaps, $agentCaps);

        $this->assertCount(1, $result);
        $this->assertSame('checkout', $result[0]->getName());
    }

    public function testNoMatchReturnsEmpty(): void
    {
        $businessCaps = [
            new UcpCapability('checkout', '1.0'),
        ];

        $agentCaps = [
            ['name' => 'catalog', 'version' => '1.0'],
        ];

        $result = $this->service->negotiate($businessCaps, $agentCaps);

        $this->assertCount(0, $result);
    }

    public function testAllMatchReturnsAll(): void
    {
        $businessCaps = [
            new UcpCapability('checkout', '1.0'),
            new UcpCapability('catalog', '1.0'),
        ];

        $agentCaps = [
            ['name' => 'checkout', 'version' => '1.0'],
            ['name' => 'catalog', 'version' => '2.0'],
        ];

        $result = $this->service->negotiate($businessCaps, $agentCaps);

        $this->assertCount(2, $result);
    }

    public function testEmptyAgentReturnsEmpty(): void
    {
        $businessCaps = [new UcpCapability('checkout', '1.0')];

        $result = $this->service->negotiate($businessCaps, []);

        $this->assertCount(0, $result);
    }
}
