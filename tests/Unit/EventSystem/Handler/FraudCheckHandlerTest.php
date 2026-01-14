<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Handler\FraudCheckHandler;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FraudScoringServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \OxidEsales\PaymentComponent\EventSystem\Handler\FraudCheckHandler
 */
class FraudCheckHandlerTest extends TestCase
{
    private FraudCheckHandler $handler;
    /** @var ContractRepositoryInterface&MockObject */
    private $contractRepository;
    /** @var FraudScoringServiceInterface&MockObject */
    private $fraudScoring;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->fraudScoring = $this->createMock(FraudScoringServiceInterface::class);

        $this->handler = new FraudCheckHandler(
            $this->contractRepository,
            $this->fraudScoring
        );
    }

    public function testLowRiskOrderPassesFraudCheck(): void
    {
        // Arrange: Low risk score
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('billingAddress', ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE']);
        $context->set('shippingAddress', ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE']);
        $context->set('email', 'valid@example.com');
        $context->set('ipAddress', '192.168.1.1');

        $event = new PaymentInitiatedEvent($context, 'pm_test', 50.00, 'EUR', '/return', '/cancel');

        $this->fraudScoring->expects($this->once())
            ->method('calculateRiskScore')
            ->willReturn(20); // Low risk

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(
                ContractCondition::TYPE_FRAUD_CHECK,
                $this->isType('array')
            );

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);

        // Assert: Fraud check condition fulfilled
    }

    public function testMismatchedAddressesTriggerReview(): void
    {
        // Arrange: Medium risk score (requires review)
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('billingAddress', ['street' => 'Billing', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE']);
        $context->set('shippingAddress', ['street' => 'Shipping', 'city' => 'Munich', 'zip' => '80331', 'country' => 'DE']);
        $context->set('email', 'customer@example.com');
        $context->set('ipAddress', '192.168.1.1');

        $event = new PaymentInitiatedEvent($context, 'pm_test', 100.00, 'EUR', '/return', '/cancel');

        $this->fraudScoring->expects($this->once())
            ->method('calculateRiskScore')
            ->willReturn(50); // Medium risk

        $contract->expects($this->never())
            ->method('fail');

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);

        // Assert: Contract marked for manual review (check context)
        $this->assertTrue($context->get('requiresManualReview'));
    }

    public function testHighValueNewCustomerRequiresReview(): void
    {
        // Arrange: High value order
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('billingAddress', ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE']);
        $context->set('shippingAddress', ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE']);
        $context->set('email', 'new@example.com');
        $context->set('ipAddress', '192.168.1.1');

        $event = new PaymentInitiatedEvent($context, 'pm_test', 1000.00, 'EUR', '/return', '/cancel');

        $this->fraudScoring->expects($this->once())
            ->method('calculateRiskScore')
            ->willReturn(60); // Medium-high risk

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);

        // Assert: Requires manual review
        $this->assertTrue($context->get('requiresManualReview'));
    }

    public function testSuspiciousEmailBlocked(): void
    {
        // Arrange: Very high risk score (block)
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('billingAddress', ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE']);
        $context->set('shippingAddress', ['street' => 'Other', 'city' => 'Munich', 'zip' => '80331', 'country' => 'DE']);
        $context->set('email', 'test@tempmail.com');
        $context->set('ipAddress', '192.168.1.1');

        $event = new PaymentInitiatedEvent($context, 'pm_test', 200.00, 'EUR', '/return', '/cancel');

        $this->fraudScoring->expects($this->once())
            ->method('calculateRiskScore')
            ->willReturn(85); // High risk

        $contract->expects($this->once())
            ->method('fail')
            ->with($this->stringContains('High fraud risk'));

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);

        // Assert: Contract failed due to high risk
    }

    public function testHandlerIgnoresNonPaymentInitiatedEvents(): void
    {
        // Arrange: Different event type
        $event = new \stdClass();

        // Act
        $this->handler->handle($event);

        // Assert: No interactions with dependencies
        $this->contractRepository->expects($this->never())->method('save');
    }

    public function testHandlerSkipsWhenNoContractInContext(): void
    {
        // Arrange: Event without contract in context
        $context = new EventContext();
        $event = new PaymentInitiatedEvent($context, 'pm_test', 50.00, 'EUR', '/return', '/cancel');

        // Act
        $this->handler->handle($event);

        // Assert: No fraud scoring performed
        $this->fraudScoring->expects($this->never())->method('calculateRiskScore');
    }
}
