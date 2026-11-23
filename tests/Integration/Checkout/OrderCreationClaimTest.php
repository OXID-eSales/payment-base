<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Checkout\Attempt\OrderCreationClaim;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 143 (AW) / MOL-39 - the order-creation claim against the real oe_payments_idempotency table.
 */
#[Group('integration')]
final class OrderCreationClaimTest extends TestCase
{
    private Connection $connection;

    private OrderCreationClaim $claim;

    private PaymentContract $contract;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)
            ->get();
        $this->claim = new OrderCreationClaim(new DoctrineIdempotencyRepository($this->connection));
        $this->contract = new PaymentContract(1, 'it_aw143_user', $this->basket(), 'it_aw143_' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM oe_payments_idempotency WHERE OXKEY LIKE :k',
            ['k' => 'order-creation:' . $this->contract->getId() . ':%']
        );
    }

    public function testClaim_WhenAlreadyClaimed_IsRefused(): void
    {
        self::assertTrue($this->claim->claim($this->contract));
        self::assertFalse($this->claim->claim($this->contract), 'a second request for the same contract version loses');
    }

    public function testClaim_AfterRelease_IsGranted(): void
    {
        $this->claim->claim($this->contract);

        $this->claim->release($this->contract);

        self::assertTrue($this->claim->claim($this->contract), 'a failed order creation does not block the next attempt');
        self::assertFalse($this->claim->claim($this->contract), 'and the new claim is exclusive again');
    }

    private function basket(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.4,
            'totalVat' => 1.6,
            'currency' => 'EUR',
        ]);
    }
}
