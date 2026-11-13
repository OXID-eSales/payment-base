<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRulesGuard;
use OxidEsales\PaymentBase\Validation\PluginPathResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 29 (VRL, appendix D of Mythos O-08): an active module that ships no validation rules is answered 422
 * before the rate limit, not with the loader's exception (HTTP 500) after it.
 */
#[CoversClass(ValidationRulesGuard::class)]
final class ValidationRulesGuardTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        $this->pluginRoot = sys_get_temp_dir() . '/vrl29-rules-' . bin2hex(random_bytes(4));
        mkdir($this->pluginRoot . '/src/Resources', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->pluginRoot . '/src/Resources/validation-rules.php');
        @rmdir($this->pluginRoot . '/src/Resources');
        @rmdir($this->pluginRoot . '/src');
        @rmdir($this->pluginRoot);
    }

    public function testAPluginWithoutRules_IsRejected422(): void
    {
        $failure = $this->guard()->check($this->context());

        $this->assertNotNull($failure);
        $this->assertSame(422, $failure->httpStatus);
    }

    public function testAPluginWithRules_Passes(): void
    {
        file_put_contents($this->pluginRoot . '/src/Resources/validation-rules.php', "<?php return ['fields' => []];\n");

        $this->assertNull($this->guard()->check($this->context()));
    }

    public function testRunsAfterTheAllowlistAndBeforeTheRateLimit(): void
    {
        $this->assertSame(56, $this->guard()->getPriority());
    }

    private function guard(): ValidationRulesGuard
    {
        $resolver = $this->createStub(PluginPathResolverInterface::class);
        $resolver->method('resolvePath')->willReturn($this->pluginRoot);

        return new ValidationRulesGuard($resolver);
    }

    private function context(): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: [],
            pluginModuleId: 'some_module',
            csrfToken: null,
            sessionId: 'sess',
            originHeader: null,
            refererHeader: null,
            clientIp: '203.0.113.1',
        );
    }
}
