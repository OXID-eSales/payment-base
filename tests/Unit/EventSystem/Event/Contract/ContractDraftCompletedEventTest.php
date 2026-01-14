<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContractDraftCompletedEvent (STRP-74).
 */
class ContractDraftCompletedEventTest extends TestCase
{
    private function createContract(): PaymentContractInterface
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

        return new PaymentContract(1, 'user123', $snapshot, 'contract_123');
    }

    private function createContext(): EventContextInterface
    {
        return new EventContext();
    }

    public function testEventImplementsCorrectInterfaces(): void
    {
        $contract = $this->createContract();
        $context = $this->createContext();

        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->assertInstanceOf(ContractDraftCompletedEventInterface::class, $event);
        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testEventContainsContract(): void
    {
        $contract = $this->createContract();
        $context = $this->createContext();

        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->assertSame($contract, $event->getContract());
    }

    public function testEventContainsContext(): void
    {
        $contract = $this->createContract();
        $context = $this->createContext();

        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventContainsBasketSnapshot(): void
    {
        $contract = $this->createContract();
        $context = $this->createContext();

        $event = new ContractDraftCompletedEvent($contract, $context);

        $basketSnapshot = $event->getBasketSnapshot();

        $this->assertInstanceOf(BasketSnapshot::class, $basketSnapshot);
        $this->assertEquals(100.0, $basketSnapshot->getTotalGross());
        $this->assertEquals('EUR', $basketSnapshot->getCurrency());
    }

    public function testEventReturnsContractId(): void
    {
        $contract = $this->createContract();
        $context = $this->createContext();

        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testEventReturnsContractState(): void
    {
        $contract = $this->createContract();
        $context = $this->createContext();

        $event = new ContractDraftCompletedEvent($contract, $context);

        $this->assertEquals('draft', $event->getContractState());
    }

    public function testEventIsReadonly(): void
    {
        $reflection = new \ReflectionClass(ContractDraftCompletedEvent::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
