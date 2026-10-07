<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Service\DeliveryAddressHashService;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S1 (2026-10-06). core's Order::validateDeliveryAddress() asks
 * Registry::getRequest()->getRequestEscapedParameter('sDeliveryAddressMD5'),
 * and Core\Request reads `$_POST[$name]`, then `$_GET[$name]` - never
 * `$_REQUEST`. This service wrote `$_REQUEST` only, so the restored hash was
 * invisible to core and a checkout without a posted order form (the headless
 * basket provider) failed with INVALIDDELADDRESSCHANGED. Pinned here so it
 * cannot drift back.
 */
#[BackupGlobals(true)]
final class DeliveryAddressHashServiceTest extends TestCase
{
    private DeliveryAddressHashService $service;

    protected function setUp(): void
    {
        unset($_POST['sDeliveryAddressMD5'], $_REQUEST['sDeliveryAddressMD5']);
        $this->service = new DeliveryAddressHashService();
    }

    public function testRestoringWritesWhereCoreReadsIt(): void
    {
        $this->service->restoreHashForValidation('abc123');

        self::assertSame('abc123', $_POST['sDeliveryAddressMD5'], 'Core\\Request::getRequestParameter() reads $_POST');
        self::assertSame('abc123', $_REQUEST['sDeliveryAddressMD5']);
        self::assertTrue($this->service->hasHash());
        self::assertSame('abc123', $this->service->getHash());
    }

    public function testAnEmptyOrMissingHashRestoresNothing(): void
    {
        $this->service->restoreHashForValidation(null);
        $this->service->restoreHashForValidation('');

        self::assertArrayNotHasKey('sDeliveryAddressMD5', $_POST);
        self::assertFalse($this->service->hasHash());
        self::assertNull($this->service->getHash());
    }

    public function testClearingRemovesItFromBothPlaces(): void
    {
        $this->service->restoreHashForValidation('abc123');

        $this->service->clearHash();

        self::assertArrayNotHasKey('sDeliveryAddressMD5', $_POST);
        self::assertArrayNotHasKey('sDeliveryAddressMD5', $_REQUEST);
        self::assertFalse($this->service->hasHash());
    }
}
