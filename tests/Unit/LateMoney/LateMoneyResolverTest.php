<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\LateMoney;

use LogicException;
use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskFlagInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\LateMoney\LateMoneyKind;
use OxidEsales\PaymentBase\LateMoney\LateMoneyResolver;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGateways;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentStatus;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * VR-23 T1 - money that arrives on a contract that can no longer take it: refunded or released once,
 * whichever delivery reports it first, and never forgotten.
 */
final class LateMoneyResolverTest extends TestCase
{
    private const PAYMENT = 'pay_late_1';

    /** @var ProviderPaymentGatewayInterface&MockObject */
    private ProviderPaymentGatewayInterface $gateway;
    /** @var ContractRepositoryInterface&MockObject */
    private ContractRepositoryInterface $contracts;
    /** @var PaymentAtRiskFlagInterface&MockObject */
    private PaymentAtRiskFlagInterface $atRisk;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(ProviderPaymentGatewayInterface::class);
        $this->gateway->method('provider')->willReturn('mollie');
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->atRisk = $this->createMock(PaymentAtRiskFlagInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testCapturedMoneyOnACancelledContractIsRefundedOnceWithTheLateKey(): void
    {
        $this->gateway->method('statusOf')->with(self::PAYMENT)
            ->willReturn(new ProviderPaymentStatus(ProviderPaymentState::Paid, self::PAYMENT, 49.9, 'EUR'));
        $this->gateway->expects(self::once())->method('refund')->with(self::PAYMENT, 'late:' . self::PAYMENT)
            ->willReturn('re_1');
        $this->gateway->expects(self::never())->method('release');
        $contract = $this->cancelledContract();
        $this->contracts->expects(self::once())->method('save')->with($contract);

        $outcome = $this->resolver()->resolve($contract, self::PAYMENT);

        self::assertSame(LateMoneyKind::Refunded, $outcome->kind);
        self::assertSame('re_1', $outcome->reference);
    }

    public function testAnAuthorizationOnATerminalContractIsReleased(): void
    {
        $this->gateway->method('statusOf')
            ->willReturn(new ProviderPaymentStatus(ProviderPaymentState::Authorized, self::PAYMENT, 49.9, 'EUR'));
        $this->gateway->expects(self::once())->method('release')->with(self::PAYMENT, 'late:' . self::PAYMENT);
        $this->gateway->expects(self::never())->method('refund');
        $this->contracts->expects(self::once())->method('save');

        $outcome = $this->resolver()->resolve($this->cancelledContract(), self::PAYMENT);

        self::assertSame(LateMoneyKind::Released, $outcome->kind);
    }

    public function testADuplicateDeliveryDoesNotRefundASecondTime(): void
    {
        $this->gateway->method('statusOf')
            ->willReturn(new ProviderPaymentStatus(ProviderPaymentState::Paid, self::PAYMENT, 49.9, 'EUR'));
        $this->gateway->expects(self::once())->method('refund')->willReturn('re_1');
        $contract = $this->cancelledContract();

        $first = $this->resolver()->resolve($contract, self::PAYMENT);
        $second = $this->resolver()->resolve($contract, self::PAYMENT);

        self::assertSame(LateMoneyKind::Refunded, $first->kind);
        self::assertSame(LateMoneyKind::AlreadySettled, $second->kind);
        self::assertSame('refunded:re_1', $second->reference);
    }

    public function testAPaymentThatHoldsNoMoneyIsNotRecordedSoALaterDeliveryIsHandled(): void
    {
        $this->gateway->method('statusOf')
            ->willReturn(new ProviderPaymentStatus(ProviderPaymentState::NoMoney, self::PAYMENT, 0.0, 'EUR'));
        $this->gateway->expects(self::never())->method('refund');
        $this->gateway->expects(self::never())->method('release');
        $this->contracts->expects(self::never())->method('save');

        $outcome = $this->resolver()->resolve($this->cancelledContract(), self::PAYMENT);

        self::assertSame(LateMoneyKind::NoMoney, $outcome->kind);
    }

    public function testARefusedRefundFlagsTheOrderAndDoesNotThrowOutOfTheWebhook(): void
    {
        $this->gateway->method('statusOf')
            ->willReturn(new ProviderPaymentStatus(ProviderPaymentState::Paid, self::PAYMENT, 49.9, 'EUR'));
        $this->gateway->method('refund')->willThrowException(new RuntimeException('insufficient balance'));
        $contract = $this->cancelledContract();
        $this->atRisk->expects(self::once())->method('raise')
            ->with($contract, AtRiskReason::LateMoneyNotReturned, self::PAYMENT);
        $this->contracts->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('error');

        $outcome = $this->resolver()->resolve($contract, self::PAYMENT);

        self::assertSame(LateMoneyKind::Failed, $outcome->kind);
    }

    public function testAFailedRefundIsRetriedByTheNextDelivery(): void
    {
        $this->gateway->method('statusOf')
            ->willReturn(new ProviderPaymentStatus(ProviderPaymentState::Paid, self::PAYMENT, 49.9, 'EUR'));
        $calls = 0;
        $this->gateway->expects(self::exactly(2))->method('refund')
            ->willReturnCallback(static function () use (&$calls): string {
                if (++$calls === 1) {
                    throw new RuntimeException('timeout');
                }

                return 're_2';
            });
        $contract = $this->cancelledContract();

        $first = $this->resolver()->resolve($contract, self::PAYMENT);
        $second = $this->resolver()->resolve($contract, self::PAYMENT);

        self::assertSame(LateMoneyKind::Failed, $first->kind);
        self::assertSame(LateMoneyKind::Refunded, $second->kind);
    }

    public function testAProviderWithoutAGatewayIsAWiringError(): void
    {
        $contract = $this->cancelledContract('paypal');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('paypal');

        $this->resolver()->resolve($contract, self::PAYMENT);
    }

    private function resolver(): LateMoneyResolver
    {
        return new LateMoneyResolver(
            new ProviderPaymentGateways([$this->gateway]),
            $this->contracts,
            $this->atRisk,
            $this->logger,
            $this->createStub(ThrowableSummaryInterface::class),
        );
    }

    private function cancelledContract(string $provider = 'mollie'): PaymentContractInterface
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 49.9, 'totalNet' => 41.9, 'totalVat' => 8.0,
            'currency' => 'EUR',
        ]), 'contract-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider($provider, 'tr_1');
        $contract->cancel('not_finished_cleanup');

        return $contract;
    }
}
