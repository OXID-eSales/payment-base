<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\ContractService;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

class ContractServiceTest extends TestCase
{
    private ContractRepository $repository;
    private ContractService $service;

    protected function setUp(): void
    {
        $this->repository = new ContractRepository();
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
        $contract = $this->service->createContract('user123', $basket);

        $found = $this->service->findActiveContractByUser('user123');

        $this->assertInstanceOf(PaymentContract::class, $found);
        $this->assertEquals($contract->getId(), $found->getId());
    }

    public function testFindActiveContractByUserReturnsNullWhenNotFound(): void
    {
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

        $expiredData = $contract->toArray();
        $expiredData['expiresAt'] = '2020-01-01 12:00:00';
        $expiredContract = PaymentContract::fromArray($expiredData);

        $this->repository->save($expiredContract);

        $count = $this->service->cleanupExpiredContracts();

        $this->assertEquals(1, $count);

        $found = $this->repository->findById($expiredContract->getId());
        $this->assertTrue($found->getState()->equals($found->getState()::expired()));
    }
}
