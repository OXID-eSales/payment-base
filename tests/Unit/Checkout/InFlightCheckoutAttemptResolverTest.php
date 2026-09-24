<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\InFlightCheckoutAttemptResolver;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistry;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * MOL-18 — a repeated "Order now" must rejoin the attempt this session already
 * has in flight instead of starting a second one. The resolver answers with
 * the PSP checkout URL to replay, or null when a new attempt is due.
 */
final class InFlightCheckoutAttemptResolverTest extends TestCase
{
    private const CHECKOUT_URL = 'https://www.mollie.com/checkout/select-method/tr_1';

    private RecordingSessionAdapter $session;
    private ContractRepositoryInterface&MockObject $contracts;
    private NotFinishedOrderRepositoryInterface&MockObject $orders;
    private InFlightCheckoutAttemptResolver $resolver;

    protected function setUp(): void
    {
        $this->session = new RecordingSessionAdapter();
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->orders = $this->createMock(NotFinishedOrderRepositoryInterface::class);
        $this->resolver = new InFlightCheckoutAttemptResolver(
            new OpenCheckoutAttemptRegistry($this->session),
            $this->contracts,
            $this->orders,
        );
    }

    public function testResolve_WhenNoOpenAttemptInSession_ReturnsNull(): void
    {
        $this->contracts->expects($this->never())->method('findById');

        $this->assertNull($this->resolver->resolve(116.5));
    }

    public function testResolve_WhenContractMissing_ReturnsNull(): void
    {
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->with('contract-1')->willReturn(null);

        $this->assertNull($this->resolver->resolve(116.5));
    }

    /**
     * @return iterable<string, array{ContractState}>
     */
    public static function settledStates(): iterable
    {
        yield 'cancelled' => [ContractState::cancelled()];
        yield 'expired' => [ContractState::expired()];
        yield 'failed' => [ContractState::failed()];
        // Not terminal, but the money was taken: nothing to rejoin.
        yield 'committed' => [ContractState::committed()];
    }

    #[DataProvider('settledStates')]
    public function testResolve_WhenContractIsSettled_ReturnsNull(ContractState $state): void
    {
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn($this->contract($state, self::CHECKOUT_URL, 'order-1', 116.5));
        $this->orders->method('isNotFinished')->willReturn(true);

        $this->assertNull($this->resolver->resolve(116.5));
    }

    public function testResolve_WhenContractHasNoRedirectUrl_ReturnsNull(): void
    {
        // The PSP payment was not created (yet): there is nothing to replay.
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn($this->contract(ContractState::pending(), null, 'order-1', 116.5));
        $this->orders->method('isNotFinished')->willReturn(true);

        $this->assertNull($this->resolver->resolve(116.5));
    }

    public function testResolve_WhenOrderIsNotNotFinished_ReturnsNull(): void
    {
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn($this->contract(ContractState::pending(), self::CHECKOUT_URL, 'order-1', 116.5));
        $this->orders->method('isNotFinished')->with('order-1')->willReturn(false);

        $this->assertNull($this->resolver->resolve(116.5));
    }

    public function testResolve_WhenContractHasNoOrder_ReturnsNull(): void
    {
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn($this->contract(ContractState::pending(), self::CHECKOUT_URL, null, 116.5));
        $this->orders->expects($this->never())->method('isNotFinished');

        $this->assertNull($this->resolver->resolve(116.5));
    }

    public function testResolve_WhenBasketTotalDiffers_ReturnsNull(): void
    {
        // Browser back from the PSP, another item in the basket, "Order now"
        // again: replaying the old redirect would charge the old amount.
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn($this->contract(ContractState::pending(), self::CHECKOUT_URL, 'order-1', 116.5));
        $this->orders->method('isNotFinished')->willReturn(true);

        $this->assertNull($this->resolver->resolve(146.4));
    }

    public function testResolve_WhenAttemptIsOpenAndBasketMatches_ReturnsRedirectUrl(): void
    {
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn($this->contract(ContractState::pending(), self::CHECKOUT_URL, 'order-1', 116.5));
        $this->orders->method('isNotFinished')->with('order-1')->willReturn(true);

        $this->assertSame(self::CHECKOUT_URL, $this->resolver->resolve(116.5));
    }

    public function testResolve_ToleratesFloatNoiseInTheBasketTotal(): void
    {
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn($this->contract(ContractState::pending(), self::CHECKOUT_URL, 'order-1', 116.5));
        $this->orders->method('isNotFinished')->willReturn(true);

        $this->assertSame(self::CHECKOUT_URL, $this->resolver->resolve(116.5000001));
    }

    public function testResolve_DoesNotForgetTheOpenAttempt(): void
    {
        // Asking must not consume the registry entry: the attempt stays
        // remembered so the eventual retire-and-recreate still finds it.
        $this->sessionHasOpenAttempt('contract-1');
        $this->contracts->method('findById')->willReturn(null);

        $this->resolver->resolve(116.5);

        $this->assertSame('contract-1', $this->session->getVariable(OpenCheckoutAttemptRegistry::SESSION_KEY));
    }

    private function sessionHasOpenAttempt(string $contractId): void
    {
        $this->session->setVariable(OpenCheckoutAttemptRegistry::SESSION_KEY, $contractId);
    }

    private function contract(
        ContractState $state,
        ?string $redirectUrl,
        ?string $orderId,
        float $snapshotTotal
    ): PaymentContractInterface&MockObject {
        $snapshot = $this->createMock(BasketSnapshot::class);
        $snapshot->method('getTotalGross')->willReturn($snapshotTotal);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getStateValue')->willReturn($state->getValue());
        $contract->method('getProviderRedirectUrl')->willReturn($redirectUrl);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }
}
