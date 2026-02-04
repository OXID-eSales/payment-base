<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\ContractService;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContractServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $repository;
    private ContractService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->service = new ContractService($this->repository);
    }

    private function createMockBasket(): object
    {
        $basket = new \stdClass();
        $basket->totalGross = 100.0;
        $basket->totalNet = 84.03;
        $basket->totalVat = 15.97;
        $basket->currency = 'EUR';

        return $basket;
    }

    public function testCreateContract(): void
    {
        $basket = $this->createMockBasket();

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(PaymentContract::class));

        $contract = $this->service->createContract('user123', $basket);

        $this->assertInstanceOf(PaymentContract::class, $contract);
        $this->assertEquals('user123', $contract->getUserId());
        $this->assertEquals(1, $contract->getShopId());
        $this->assertNotEmpty($contract->getConditions());
        $this->assertTrue($contract->getState()->isDraft());
    }

    public function testCreateContractWithCustomConditions(): void
    {
        $basket = $this->createMockBasket();

        $this->repository->expects($this->once())
            ->method('save');

        $contract = $this->service->createContract(
            'user123',
            $basket,
            [ContractCondition::TYPE_PAYMENT_AUTHORIZED]
        );

        $this->assertCount(1, $contract->getConditions());
        $this->assertEquals(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $contract->getConditions()[0]->getType());
    }

    public function testFindActiveContractByUser(): void
    {
        $basket = $this->createMockBasket();

        $contract = new PaymentContract(
            1,
            'user123',
            BasketSnapshot::fromArray([
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.03,
                'totalVat' => 15.97,
                'currency' => 'EUR',
            ])
        );

        $this->repository->expects($this->once())
            ->method('findActiveByUserId')
            ->with('user123')
            ->willReturn($contract);

        $found = $this->service->findActiveContractByUser('user123');

        $this->assertInstanceOf(PaymentContract::class, $found);
        $this->assertEquals($contract->getId(), $found->getId());
    }

    public function testFindActiveContractByUserReturnsNullWhenNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('findActiveByUserId')
            ->with('nonexistent')
            ->willReturn(null);

        $found = $this->service->findActiveContractByUser('nonexistent');

        $this->assertNull($found);
    }

    public function testCleanupExpiredContracts(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => '2020-01-01 12:00:00',
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);

        // Create an expired contract
        $expiredData = $contract->toArray();
        $expiredData['expiresAt'] = '2020-01-01 12:00:00';
        $expiredContract = PaymentContract::fromArray($expiredData);

        $this->repository->expects($this->once())
            ->method('findExpired')
            ->willReturn([$expiredContract]);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PaymentContract $c) {
                return $c->getState()->isExpired();
            }));

        $count = $this->service->cleanupExpiredContracts();

        $this->assertEquals(1, $count);
    }
}
