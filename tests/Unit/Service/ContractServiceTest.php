<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Adapter\ShopContextInterface;
use OxidEsales\PaymentBase\Service\ContractService;
use OxidEsales\PaymentBase\Service\ContractTotalsException;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use PHPUnit\Framework\MockObject\MockObject;
use OxidEsales\PaymentBase\Tests\Unit\Fixture\PricedBasket;
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
    private ShopContextInterface&MockObject $shop;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->registry = $this->createMock(OpenCheckoutAttemptRegistryInterface::class);
        $this->closer = new RecordingAttemptCloser();
        $this->shop = $this->createMock(ShopContextInterface::class);
        $this->shop->method('getShopId')->willReturn(1);
        $this->service = new ContractService(
            $this->repository,
            $this->registry,
            new PaymentAttemptClosers([$this->closer]),
            $this->shop
        );
    }

    /**
     * VR-22 D4 (C3) - every contract was stamped shop 1; on an EE subshop the deferred
     * order mail then used shop 1's SMTP / sender.
     */
    public function testCreateContract_StampsTheShopOfTheContext(): void
    {
        $shop = $this->createMock(ShopContextInterface::class);
        $shop->method('getShopId')->willReturn(7);
        $service = new ContractService($this->repository, $this->registry, new PaymentAttemptClosers([]), $shop);

        $contract = $service->createContract('user123', $this->createMockBasket());

        self::assertSame(7, $contract->getShopId());
    }

    private function createMockBasket(?string $currency = 'CHF', bool $withPrice = true): object
    {
        return new PricedBasket($currency, $withPrice);
    }

    /**
     * VR-20 R4 (S3) — the contract amount is what every provider charges. It was
     * built with silent fallbacks: EUR when the currency object was missing (the
     * "CHF 100 charged as EUR 100" defect ShopCurrency was written against) and
     * 0.00 when the basket had no price.
     */
    public function testCreateContract_TakesAmountAndCurrencyFromTheBasket(): void
    {
        $contract = $this->service->createContract('user123', $this->createMockBasket('CHF'));

        self::assertSame('CHF', $contract->getCurrency());
        self::assertSame(100.0, $contract->getAmount());
    }

    public function testCreateContract_WithoutABasketCurrency_RefusesToGuessOne(): void
    {
        $this->repository->expects(self::never())->method('save');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to assume a currency');

        $this->service->createContract('user123', $this->createMockBasket(null));
    }

    public function testCreateContract_WithoutABasketPrice_RefusesAZeroContract(): void
    {
        $this->repository->expects(self::never())->method('save');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('basket has no price');

        $this->service->createContract('user123', $this->createMockBasket('EUR', withPrice: false));
    }

    /**
     * VR-26 A2 (owner decision D2): a 100 % voucher leaves nothing to charge; a provider cannot
     * take a 0.00 payment, so no contract is made and the checkout is refused with a reason.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('uncollectableTotals')]
    public function zeroTotalIsRefused(float $gross): void
    {
        $this->repository->expects(self::never())->method('save');
        $this->expectException(ContractTotalsException::class);
        $this->expectExceptionMessage('nothing to charge');

        $this->service->createContract('user123', new PricedBasket('EUR', true, $gross, 0.0, 0.0));
    }

    /**
     * @return array<string, array{float}>
     */
    public static function uncollectableTotals(): array
    {
        return ['zero' => [0.0], 'rounds to zero' => [0.004]];
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
