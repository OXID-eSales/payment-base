<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Mcp\Ucp;

use OxidEsales\PaymentComponent\Mcp\Ucp\UcpCapability;
use PHPUnit\Framework\TestCase;

class UcpCapabilityTest extends TestCase
{
    public function testBasicToArray(): void
    {
        $cap = new UcpCapability('dev.ucp.shopping.checkout', '2026-01-11');
        $data = $cap->toArray();

        $this->assertSame('dev.ucp.shopping.checkout', $data['name']);
        $this->assertSame('2026-01-11', $data['version']);
        $this->assertArrayNotHasKey('spec', $data);
        $this->assertArrayNotHasKey('extensions', $data);
    }

    public function testWithSpec(): void
    {
        $cap = new UcpCapability('cap1', '1.0', 'https://spec.test/v1');
        $data = $cap->toArray();

        $this->assertSame('https://spec.test/v1', $data['spec']);
    }

    public function testWithExtensions(): void
    {
        $ext = new UcpCapability('ext1', '1.0');
        $cap = new UcpCapability('parent', '1.0', null, [$ext]);
        $data = $cap->toArray();

        $this->assertArrayHasKey('extensions', $data);
        $this->assertCount(1, $data['extensions']);
        $this->assertSame('ext1', $data['extensions'][0]['name']);
    }

    public function testGetName(): void
    {
        $cap = new UcpCapability('my_cap', '1.0');
        $this->assertSame('my_cap', $cap->getName());
    }

    public function testGetVersion(): void
    {
        $cap = new UcpCapability('my_cap', '2.0');
        $this->assertSame('2.0', $cap->getVersion());
    }
}
