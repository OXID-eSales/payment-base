<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\FraudCheckHandler;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FraudCheckServiceInterface;
use OxidEsales\PaymentBase\Adapter\Response\FraudCheckResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \OxidEsales\PaymentBase\EventSystem\Handler\FraudCheckHandler
 */
class FraudCheckHandlerTest extends TestCase
{
    private FraudCheckHandler $handler;
    /** @var ContractRepositoryInterface&MockObject */
    private ContractRepositoryInterface $contractRepository;
    /** @var FraudCheckServiceInterface&MockObject */
    private FraudCheckServiceInterface $fraudCheckService;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->fraudCheckService = $this->createMock(FraudCheckServiceInterface::class);

        $this->handler = new FraudCheckHandler(
            $this->contractRepository,
            $this->fraudCheckService,
            true // enabled by default
        );
    }

    // =========================================================================
    // Event handling tests
    // =========================================================================

    public function testHandlesPaymentAuthorizedEvent(): void
    {
        $this->assertEquals(PaymentAuthorizedEvent::class, FraudCheckHandler::getHandledEventClass());
    }

    public function testFulfillsConditionOnPassedFraudCheck(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $context->set('contract', $contract);

        $event = new PaymentAuthorizedEvent($context, 'pi_123', 'order_123', 100.0, 'EUR');

        $this->fraudCheckService->expects($this->once())
            ->method('check')
            ->with($contract)
            ->willReturn(FraudCheckResponse::success(0.25));

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(
                ContractCondition::TYPE_FRAUD_CHECK,
                $this->callback(fn($data) =>
                    isset($data['checkedAt']) &&
                    $data['passed'] === true &&
                    $data['score'] === 0.25
                )
            );

        $contract->expects($this->never())
            ->method('fail');

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->handler->handle($event);
    }

    public function testFailsContractOnFailedFraudCheck(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $context->set('contract', $contract);

        $event = new PaymentAuthorizedEvent($context, 'pi_123', 'order_123', 100.0, 'EUR');

        $this->fraudCheckService->expects($this->once())
            ->method('check')
            ->with($contract)
            ->willReturn(FraudCheckResponse::failure(0.85, 'High risk score from Stripe Radar'));

        $contract->expects($this->once())
            ->method('fail')
            ->with($this->stringContains('Fraud check failed'));

        $contract->expects($this->never())
            ->method('fulfillCondition');

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->handler->handle($event);
    }

    // =========================================================================
    // Handler ignores other events
    // =========================================================================

    public function testIgnoresNonPaymentAuthorizedEvents(): void
    {
        $event = new \stdClass();

        $this->fraudCheckService->expects($this->never())
            ->method('check');

        $this->contractRepository->expects($this->never())
            ->method('save');

        $this->handler->handle($event);
    }

    public function testSkipsWhenNoContractInContext(): void
    {
        $context = new EventContext();
        $event = new PaymentAuthorizedEvent($context, 'pi_123', 'order_123', 100.0, 'EUR');

        $this->fraudCheckService->expects($this->never())
            ->method('check');

        $this->contractRepository->expects($this->never())
            ->method('save');

        $this->handler->handle($event);
    }

    // =========================================================================
    // Configuration tests
    // =========================================================================

    public function testSkipsWhenDisabled(): void
    {
        // Create handler with disabled flag
        $handler = new FraudCheckHandler(
            $this->contractRepository,
            $this->fraudCheckService,
            false // disabled
        );

        $contract = $this->createMockContract();
        $context = new EventContext();
        $context->set('contract', $contract);

        $event = new PaymentAuthorizedEvent($context, 'pi_123', 'order_123', 100.0, 'EUR');

        // When disabled, should immediately fulfill condition without checking fraud
        $this->fraudCheckService->expects($this->never())
            ->method('check');

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(
                ContractCondition::TYPE_FRAUD_CHECK,
                $this->callback(fn($data) => $data['skipped'] === true)
            );

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler->handle($event);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testPassesWithScoreExactlyAtThreshold(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $context->set('contract', $contract);

        $event = new PaymentAuthorizedEvent($context, 'pi_123', 'order_123', 100.0, 'EUR');

        // Score at exactly 0.7 (the threshold) should be determined by FraudCheckService
        // The handler trusts the service's pass/fail decision
        $this->fraudCheckService->expects($this->once())
            ->method('check')
            ->with($contract)
            ->willReturn(FraudCheckResponse::success(0.70));

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(ContractCondition::TYPE_FRAUD_CHECK, $this->isType('array'));

        $this->contractRepository->expects($this->once())
            ->method('save');

        $this->handler->handle($event);
    }

    public function testFailsWithScoreJustAboveThreshold(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $context->set('contract', $contract);

        $event = new PaymentAuthorizedEvent($context, 'pi_123', 'order_123', 100.0, 'EUR');

        // Score at 0.71 (just above threshold) should fail
        $this->fraudCheckService->expects($this->once())
            ->method('check')
            ->with($contract)
            ->willReturn(FraudCheckResponse::failure(0.71, 'Score exceeds threshold'));

        $contract->expects($this->once())
            ->method('fail')
            ->with($this->stringContains('Fraud check failed'));

        $this->contractRepository->expects($this->once())
            ->method('save');

        $this->handler->handle($event);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createMockContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract123');

        return $contract;
    }
}
