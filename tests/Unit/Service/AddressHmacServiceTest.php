<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use InvalidArgumentException;
use OxidEsales\PaymentComponent\Service\AddressHmacService;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 68b: M9 — Address HMAC binding.
 *
 * @covers \OxidEsales\PaymentComponent\Service\AddressHmacService
 * @group sprint-68b
 * @group security
 */
final class AddressHmacServiceTest extends TestCase
{
    /** @test */
    public function hmacDiffersFromPlainMd5(): void
    {
        $service = new AddressHmacService('test_secret');
        $hash = md5('some_address_data');

        $hmac = $service->sign($hash);

        $this->assertNotSame($hash, $hmac);
        $this->assertNotEmpty($hmac);
    }

    /** @test */
    public function hmacRequiresSecret(): void
    {
        $serviceA = new AddressHmacService('secret_A');
        $serviceB = new AddressHmacService('secret_B');
        $hash = 'abc123';

        $hmacA = $serviceA->sign($hash);
        $hmacB = $serviceB->sign($hash);

        $this->assertNotSame($hmacA, $hmacB);
    }

    /** @test */
    public function hmacVerifiesSuccessfully(): void
    {
        $service = new AddressHmacService('test_secret');
        $hash = 'address_hash_value';

        $hmac = $service->sign($hash);

        $this->assertTrue($service->verify($hash, $hmac));
    }

    /** @test */
    public function hmacRejectsTamperedHash(): void
    {
        $service = new AddressHmacService('test_secret');

        $hmac = $service->sign('original_hash');

        $this->assertFalse($service->verify('tampered_hash', $hmac));
    }

    /** @test */
    public function hmacRejectsEmptyHash(): void
    {
        $service = new AddressHmacService('test_secret');

        $this->assertFalse($service->verify('', 'some_hmac'));
        $this->assertFalse($service->verify('some_hash', ''));
    }

    /** @test */
    public function constructorRejectsEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AddressHmacService('');
    }
}
