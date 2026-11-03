<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Payment\DbalOrderLock;
use OxidEsales\PaymentBase\Payment\OrderLockUnavailableException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * VR-22 A3 — the named lock really excludes a second connection, and is free again after the work.
 */
#[Group('integration')]
final class DbalOrderLockTest extends TestCase
{
    private Connection $holder;

    private Connection $other;

    protected function setUp(): void
    {
        $this->holder = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        $this->other = DriverManager::getConnection($this->holder->getParams());
    }

    protected function tearDown(): void
    {
        $this->other->close();
    }

    public function testASecondConnectionCannotRunWhileTheFirstHoldsTheLock(): void
    {
        $outcome = (new DbalOrderLock($this->holder, 1))->runLocked('it_vr22_order', function (): string {
            try {
                (new DbalOrderLock($this->other, 1))->runLocked('it_vr22_order', static fn (): string => 'ran');

                return 'ran';
            } catch (OrderLockUnavailableException) {
                return 'busy';
            }
        });

        self::assertSame('busy', $outcome);
    }

    public function testTheLockIsFreeAgainAfterTheWork_EvenWhenItThrew(): void
    {
        try {
            (new DbalOrderLock($this->holder, 1))->runLocked('it_vr22_order', static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame('ran', (new DbalOrderLock($this->other, 1))->runLocked('it_vr22_order', static fn (): string => 'ran'));
    }
}
