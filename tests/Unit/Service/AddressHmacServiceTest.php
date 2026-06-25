<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Service\AddressHmacService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 68b: M9 — Address HMAC binding.
 */
#[CoversClass(\OxidEsales\PaymentBase\Service\AddressHmacService::class)]
#[Group('sprint-68b')]
#[Group('security')]
final class AddressHmacServiceTest extends TestCase
{
    #[Test]
    public function hmacDiffersFromPlainMd5(): void
    {
        $service = new AddressHmacService('test_secret');
        $hash = md5('some_address_data');

        $hmac = $service->sign($hash);

        $this->assertNotSame($hash, $hmac);
        $this->assertNotEmpty($hmac);
    }

    #[Test]
    public function hmacRequiresSecret(): void
    {
        $serviceA = new AddressHmacService('secret_A');
        $serviceB = new AddressHmacService('secret_B');
        $hash = 'abc123';

        $hmacA = $serviceA->sign($hash);
        $hmacB = $serviceB->sign($hash);

        $this->assertNotSame($hmacA, $hmacB);
    }

    #[Test]
    public function hmacVerifiesSuccessfully(): void
    {
        $service = new AddressHmacService('test_secret');
        $hash = 'address_hash_value';

        $hmac = $service->sign($hash);

        $this->assertTrue($service->verify($hash, $hmac));
    }

    #[Test]
    public function hmacRejectsTamperedHash(): void
    {
        $service = new AddressHmacService('test_secret');

        $hmac = $service->sign('original_hash');

        $this->assertFalse($service->verify('tampered_hash', $hmac));
    }

    #[Test]
    public function hmacRejectsEmptyHash(): void
    {
        $service = new AddressHmacService('test_secret');

        $this->assertFalse($service->verify('', 'some_hmac'));
        $this->assertFalse($service->verify('some_hash', ''));
    }

    #[Test]
    public function constructorRejectsEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AddressHmacService('');
    }
}
