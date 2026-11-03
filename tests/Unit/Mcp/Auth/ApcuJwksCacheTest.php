<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentBase\Mcp\Auth\ApcuJwksCache;
use PHPUnit\Framework\TestCase;

final class ApcuJwksCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled in this CLI');
        }
    }

    public function testStoresAndReturnsTheDocument(): void
    {
        $cache = new ApcuJwksCache();

        $cache->set('k-test', '{"keys":[]}', 60);

        self::assertSame('{"keys":[]}', $cache->get('k-test'));
    }

    public function testUnknownKeyIsNull(): void
    {
        self::assertNull((new ApcuJwksCache())->get('never-stored'));
    }
}
