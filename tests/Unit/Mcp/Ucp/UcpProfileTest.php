<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Mcp\Ucp;

use OxidEsales\PaymentComponent\Mcp\Ucp\UcpCapability;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfile;
use PHPUnit\Framework\TestCase;

class UcpProfileTest extends TestCase
{
    public function testToArrayReturnsUcpVersion(): void
    {
        $profile = new UcpProfile('https://shop.test/ucp', []);
        $data = $profile->toArray();

        $this->assertSame('2026-01-11', $data['ucp_version']);
    }

    public function testToArrayIncludesRestEndpoint(): void
    {
        $profile = new UcpProfile('https://shop.test/ucp', []);
        $data = $profile->toArray();

        $this->assertSame(
            'https://shop.test/ucp',
            $data['services']['dev.ucp.shopping']['rest']['endpoint']
        );
    }

    public function testToArrayIncludesCapabilities(): void
    {
        $cap = new UcpCapability('dev.ucp.shopping.checkout', '2026-01-11');
        $profile = new UcpProfile('https://shop.test/ucp', [$cap]);
        $data = $profile->toArray();

        $this->assertCount(1, $data['capabilities']);
        $this->assertSame('dev.ucp.shopping.checkout', $data['capabilities'][0]['name']);
    }

    public function testToArrayIncludesPaymentHandlers(): void
    {
        $handlers = [
            ['id' => 'stripe', 'spec' => 'https://stripe.com/ucp-handler', 'version' => '2026-01-11'],
        ];
        $profile = new UcpProfile('https://shop.test/ucp', [], $handlers);
        $data = $profile->toArray();

        $this->assertCount(1, $data['payment']['handlers']);
        $this->assertSame('stripe', $data['payment']['handlers'][0]['id']);
    }

    public function testGetCapabilities(): void
    {
        $cap1 = new UcpCapability('cap1', '1.0');
        $cap2 = new UcpCapability('cap2', '2.0');
        $profile = new UcpProfile('https://shop.test/ucp', [$cap1, $cap2]);

        $this->assertCount(2, $profile->getCapabilities());
    }
}
