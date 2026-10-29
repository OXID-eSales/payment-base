<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Service\ContractService;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\Checkout\Attempt\CheckoutAttemptAlreadyPaidException;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptCloserInterface;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptClosers;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistryInterface;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\ReplacedAttempts;

/** Answers a scripted closure and records what it was asked. */
final class RecordingAttemptCloser implements PaymentAttemptCloserInterface
{
    public AttemptClosure $answer = AttemptClosure::Closed;
    /** @var list<string> */
    public array $closed = [];

    public function provider(): string
    {
        return 'stripe';
    }

    public function close(PaymentContractInterface $contract): AttemptClosure
    {
        $this->closed[] = (string) $contract->getId();

        return $this->answer;
    }
}

class ContractServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $repository;
    private ContractService $service;
    private OpenCheckoutAttemptRegistryInterface&MockObject $registry;
    private RecordingAttemptCloser $closer;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->registry = $this->createMock(OpenCheckoutAttemptRegistryInterface::class);
        $this->closer = new RecordingAttemptCloser();
        $this->service = new ContractService($this->repository, $this->registry, new PaymentAttemptClosers([$this->closer]));
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

    // ── OPC-175: one order, one contract ──

    public function testCreateContract_WithAnOpenAttempt_ContinuesTheSameContract(): void
    {
        $open = $this->openStripeAttempt('user123');
        $oldBasket = $open->getBasketSnapshot();
        $this->registry->method('peek')->willReturn('ctr-open');
        $this->repository->method('findById')->with('ctr-open')->willReturn($open);
        $this->repository->expects($this->once())->method('save')->with($open);

        $contract = $this->service->createContract('user123', $this->createMockBasket(), ['payment_authorized']);

        $this->assertSame($open, $contract);
        $this->assertSame(['ctr-open'], $this->closer->closed);
        $this->assertTrue($contract->getState()->isDraft());
        $this->assertSame('order-open', $contract->getOrderId());
        $this->assertNotSame($oldBasket, $contract->getBasketSnapshot(), 'the contract takes the new basket');
        $this->assertSame('cs_open', ReplacedAttempts::of($contract)[0]['providerOrderId']);
    }

    public function testCreateContract_WithAPaidOpenAttempt_IsRefusedAndChangesNothing(): void
    {
        $this->closer->answer = AttemptClosure::Paid;
        $this->registry->method('peek')->willReturn('ctr-open');
        $this->repository->method('findById')->willReturn($this->openStripeAttempt('user123'));
        $this->repository->expects($this->never())->method('save');

        $this->expectException(CheckoutAttemptAlreadyPaidException::class);
        $this->service->createContract('user123', $this->createMockBasket(), ['payment_authorized']);
    }

    public function testCreateContract_WithoutAnOpenAttempt_CreatesANewContract(): void
    {
        $this->registry->method('peek')->willReturn(null);
        $this->repository->expects($this->never())->method('findById');

        $contract = $this->service->createContract('user123', $this->createMockBasket());

        $this->assertNull($contract->getOrderId());
        $this->assertSame([], $this->closer->closed);
    }

    public function testCreateContract_TheOpenAttemptOfAnotherUser_IsNotContinued(): void
    {
        $this->registry->method('peek')->willReturn('ctr-open');
        $this->repository->method('findById')->willReturn($this->openStripeAttempt('someone-else'));

        $contract = $this->service->createContract('user123', $this->createMockBasket());

        $this->assertNotSame('ctr-open', $contract->getId());
        $this->assertSame([], $this->closer->closed);
    }

    public function testCreateContract_ASettledAttempt_IsNotContinued(): void
    {
        $settled = $this->openStripeAttempt('user123');
        $settled->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $settled->commitToOrder('order-open');
        $this->registry->method('peek')->willReturn('ctr-open');
        $this->repository->method('findById')->willReturn($settled);

        $contract = $this->service->createContract('user123', $this->createMockBasket());

        $this->assertNotSame('ctr-open', $contract->getId());
    }

    private function openStripeAttempt(string $userId): PaymentContract
    {
        $contract = new PaymentContract(1, $userId, BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 42.0, 'totalVat' => 0.0, 'currency' => 'EUR',
        ]), 'ctr-open');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-open');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'cs_open');

        return $contract;
    }
}
