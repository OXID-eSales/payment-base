<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractCommitmentHandler;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\TestCase;

final class ContractCommitmentHandlerTest extends TestCase
{
    public function testCommitsAndDispatchesOnAutoCapture(): void
    {
        $contract = $this->readyToCommitContract();

        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->expects(self::once())->method('save');

        $state = $this->createMock(OrderPaymentStateServiceInterface::class);
        $state->expects(self::once())->method('updateTransactionId')->with('oxid_ord_1', 'auth_1');
        $state->expects(self::once())->method('updateTransactionStatus')->with('oxid_ord_1', 'OK');
        $state->expects(self::once())->method('markOrderAsPaid')->with('oxid_ord_1');

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(function (EventInterface $e) use (&$dispatched): EventInterface {
                $dispatched[] = $e;
                return $e;
            });

        $handler = new ContractCommitmentHandler($contracts, $state, $dispatcher);
        $handler->handle($this->event($contract, providerName: 'stripe', requiresCapture: false, authId: 'auth_1'));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(ContractCommittedEvent::class, $dispatched[0]);
        self::assertSame('committed', $contract->getStateValue());
    }

    public function testSkipsOxpaidOnManualCapture(): void
    {
        $contract = $this->readyToCommitContract();
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $state = $this->createMock(OrderPaymentStateServiceInterface::class);
        $state->expects(self::never())->method('markOrderAsPaid');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $handler = new ContractCommitmentHandler($contracts, $state, $dispatcher);
        $handler->handle($this->event($contract, providerName: 'stripe', requiresCapture: true, authId: 'auth_1'));
    }

    public function testSkipsIfContractNotReadyToCommit(): void
    {
        $contract = new PaymentContract(1, 'user_1', $this->snapshot());

        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->expects(self::never())->method('save');
        $state = $this->createMock(OrderPaymentStateServiceInterface::class);
        $state->expects(self::never())->method('updateTransactionStatus');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        (new ContractCommitmentHandler($contracts, $state, $dispatcher))->handle(
            $this->event($contract, providerName: 'stripe', requiresCapture: false, authId: 'auth_1'),
        );
    }

    public function testGatedOnProviderName(): void
    {
        $contract = $this->readyToCommitContract();
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->expects(self::never())->method('save');
        $state = $this->createMock(OrderPaymentStateServiceInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $context = new EventContext(); // no providerName, no contract provider
        $context->setContract($contract);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        (new ContractCommitmentHandler($contracts, $state, $dispatcher))->handle($event);
    }

    public function testLiskovCompatibility(): void
    {
        foreach (['stripe', 'paypal'] as $providerName) {
            $contract = $this->readyToCommitContract();
            $contracts = $this->createMock(ContractRepositoryInterface::class);
            $state = $this->createMock(OrderPaymentStateServiceInterface::class);
            $dispatcher = $this->createMock(EventDispatcherInterface::class);
            $dispatcher->expects(self::once())->method('dispatch');

            (new ContractCommitmentHandler($contracts, $state, $dispatcher))->handle(
                $this->event($contract, providerName: $providerName, requiresCapture: false, authId: 'auth_' . $providerName),
            );
            self::assertSame('committed', $contract->getStateValue());
        }
    }

    private function readyToCommitContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user_1', $this->snapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('oxid_ord_1');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        return $contract;
    }

    private function snapshot(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [],
            'totalGross' => 10.0,
            'totalNet' => 10.0,
            'totalVat' => 0.0,
            'currency' => 'EUR',
        ]);
    }

    private function event(
        PaymentContract $contract,
        string $providerName,
        bool $requiresCapture,
        string $authId,
    ): ContractReadyToCommitEvent {
        $context = new EventContext([
            'providerName' => $providerName,
            'requiresCapture' => $requiresCapture,
            'authorizationId' => $authId,
        ]);
        $context->setContract($contract);
        return new ContractReadyToCommitEvent($contract, $context, []);
    }
}
