<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Repository;

use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;

class ContractRepositoryTest extends TestCase
{
    private ContractRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new ContractRepository();
    }

    private function createTestContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        return new PaymentContract(1, 'user123', $snapshot);
    }

    public function testSaveAndFindById(): void
    {
        $contract = $this->createTestContract();
        $contractId = $contract->getId();

        $this->repository->save($contract);
        $found = $this->repository->findById($contractId);

        $this->assertInstanceOf(PaymentContract::class, $found);
        $this->assertEquals($contractId, $found->getId());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $found = $this->repository->findById('non_existent_id');

        $this->assertNull($found);
    }

    public function testFindByProviderOrderId(): void
    {
        $contract = $this->createTestContract();
        $contract->setProvider('stripe', 'pi_123456');

        $this->repository->save($contract);
        $found = $this->repository->findByProviderOrderId('pi_123456');

        $this->assertInstanceOf(PaymentContract::class, $found);
        $this->assertEquals('pi_123456', $found->getProviderOrderId());
    }

    public function testFindByUserId(): void
    {
        $contract1 = $this->createTestContract();
        $contract2 = $this->createTestContract();

        $this->repository->save($contract1);
        $this->repository->save($contract2);

        $found = $this->repository->findByUserId('user123');

        $this->assertIsArray($found);
        $this->assertCount(2, $found);
    }

    public function testFindActiveByUserId(): void
    {
        $contract = $this->createTestContract();

        $this->repository->save($contract);
        $found = $this->repository->findActiveByUserId('user123');

        $this->assertInstanceOf(PaymentContract::class, $found);
        $this->assertEquals('user123', $found->getUserId());
    }

    public function testDelete(): void
    {
        $contract = $this->createTestContract();
        $contractId = $contract->getId();

        $this->repository->save($contract);
        $this->repository->delete($contractId);

        $found = $this->repository->findById($contractId);
        $this->assertNull($found);
    }
}
