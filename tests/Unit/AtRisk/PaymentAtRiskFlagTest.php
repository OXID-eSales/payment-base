<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\AtRisk;

use OxidEsales\PaymentBase\AtRisk\AtRiskOwnerMailerInterface;
use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRisk;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskFlag;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskRepositoryInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * VR-23 T1 - "payment at risk": one flag per contract, reason and provider reference; the owner is told once.
 * The merchant decides what happens to the order - nothing here cancels or refunds.
 */
final class PaymentAtRiskFlagTest extends TestCase
{
    /** @var PaymentAtRiskRepositoryInterface&MockObject */
    private PaymentAtRiskRepositoryInterface $flags;
    /** @var AtRiskOwnerMailerInterface&MockObject */
    private AtRiskOwnerMailerInterface $mailer;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->flags = $this->createMock(PaymentAtRiskRepositoryInterface::class);
        $this->mailer = $this->createMock(AtRiskOwnerMailerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testANewFlagIsStoredWithContractOrderReasonAndReferenceAndTheOwnerIsMailed(): void
    {
        $contract = $this->committedContract();
        $this->flags->expects(self::once())->method('insertIfAbsent')
            ->with(self::callback(static function (PaymentAtRisk $flag): bool {
                return $flag->contractId === 'contract-1'
                    && $flag->orderId === 'order-1'
                    && $flag->reason === AtRiskReason::DisputeOpened
                    && $flag->reference === 'dp_1';
            }))->willReturn(true);
        $this->flags->method('claimOwnerMail')->willReturn(true);
        $this->mailer->expects(self::once())->method('notify')->willReturn(true);

        self::assertTrue($this->flag()->raise($contract, AtRiskReason::DisputeOpened, 'dp_1'));
    }

    public function testTheSameFlagRaisedAgainDoesNotMailTheOwnerAgain(): void
    {
        $this->flags->method('insertIfAbsent')->willReturn(false);
        $this->flags->method('claimOwnerMail')->willReturn(false);
        $this->mailer->expects(self::never())->method('notify');

        self::assertFalse($this->flag()->raise($this->committedContract(), AtRiskReason::DisputeOpened, 'dp_1'));
    }

    public function testAMailThatFailsIsReleasedForTheNextRaiseAndNeverThrows(): void
    {
        $this->flags->method('insertIfAbsent')->willReturn(true);
        $this->flags->method('claimOwnerMail')->willReturn(true);
        $this->mailer->method('notify')->willThrowException(new RuntimeException('smtp down'));
        $this->flags->expects(self::once())->method('releaseOwnerMail');
        $this->logger->expects(self::once())->method('error');

        self::assertTrue($this->flag()->raise($this->committedContract(), AtRiskReason::CaptureDenied));
    }

    public function testAMailTheShopRefusesIsReleasedToo(): void
    {
        $this->flags->method('insertIfAbsent')->willReturn(true);
        $this->flags->method('claimOwnerMail')->willReturn(true);
        $this->mailer->method('notify')->willReturn(false);
        $this->flags->expects(self::once())->method('releaseOwnerMail');

        $this->flag()->raise($this->committedContract(), AtRiskReason::CaptureDenied);
    }

    public function testAcknowledgingClearsTheFlagWithoutTouchingTheContract(): void
    {
        $this->flags->expects(self::once())->method('acknowledge')->with('contract-1', AtRiskReason::DisputeOpened, 'dp_1')
            ->willReturn(true);

        self::assertTrue($this->flag()->acknowledge('contract-1', AtRiskReason::DisputeOpened, 'dp_1'));
    }

    private function flag(): PaymentAtRiskFlag
    {
        return new PaymentAtRiskFlag($this->flags, $this->mailer, $this->logger);
    }

    private function committedContract(): PaymentContractInterface
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6,
            'currency' => 'EUR',
        ]), 'contract-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');

        return $contract;
    }
}
