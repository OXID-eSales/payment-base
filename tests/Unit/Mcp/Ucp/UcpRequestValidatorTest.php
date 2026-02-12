<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Mcp\Ucp;

use OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator;
use PHPUnit\Framework\TestCase;

class UcpRequestValidatorTest extends TestCase
{
    private UcpRequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UcpRequestValidator();
    }

    public function testValidHeadersPass(): void
    {
        $result = $this->validator->validateHeaders([
            'request-id' => 'req_123',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testMissingRequestIdFails(): void
    {
        $result = $this->validator->validateHeaders([]);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Request-Id', $result['errors'][0]);
    }

    public function testExtractAgentProfileFromUcpAgentHeader(): void
    {
        $url = $this->validator->extractAgentProfile([
            'ucp-agent' => 'profile="https://agent.test/.well-known/ucp"',
        ]);

        $this->assertSame('https://agent.test/.well-known/ucp', $url);
    }

    public function testExtractAgentProfileReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->validator->extractAgentProfile([]));
    }

    public function testExtractAgentProfileReturnsNullForMalformedHeader(): void
    {
        $this->assertNull($this->validator->extractAgentProfile([
            'ucp-agent' => 'not-a-profile-header',
        ]));
    }
}
