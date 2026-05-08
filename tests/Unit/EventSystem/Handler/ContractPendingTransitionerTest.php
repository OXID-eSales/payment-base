<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Return\CheckoutReturnCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\ContractPendingTransitioner;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use PHPUnit\Framework\TestCase;

final class ContractPendingTransitionerTest extends TestCase
{
    public function testSkipsDraftState(): void
    {
        // DRAFT → NOT_FINISHED is EarlyOrderCreationHandler's job. This handler only
        // handles NOT_FINISHED → PENDING; seeing DRAFT means upstream skipped a step
        // and we don't mask the bug by throwing.
        $contract = $this->draftContract();
        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new ContractPendingTransitioner($repo);
        $handler->handle($this->successEvent($contract, providerName: 'stripe'));

        self::assertSame('draft', $contract->getStateValue());
    }

    public function testTransitionsNotFinishedToPending(): void
    {
        $contract = $this->draftContract();
        $contract->transitionToNotFinished('oxid_order_1');
        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $handler = new ContractPendingTransitioner($repo);
        $handler->handle($this->successEvent($contract, providerName: 'paypal'));

        self::assertSame('pending', $contract->getStateValue());
    }

    public function testNoOpIfAlreadyPending(): void
    {
        $contract = $this->draftContract();
        $contract->transitionToNotFinished('oxid_order_1');
        $contract->transitionToPending();

        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new ContractPendingTransitioner($repo);
        $handler->handle($this->successEvent($contract, providerName: 'stripe'));

        self::assertSame('pending', $contract->getStateValue());
    }

    public function testNoOpOnFailedResolution(): void
    {
        $contract = $this->draftContract();
        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $context = new EventContext(['providerName' => 'stripe']);
        $context->setContract($contract);
        $event = new CheckoutReturnCompletedEvent(
            $context,
            ReturnResolution::failed('x', 'y'),
        );

        (new ContractPendingTransitioner($repo))->handle($event);
        self::assertSame('draft', $contract->getStateValue());
    }

    public function testGatedOnProviderName(): void
    {
        $contract = $this->draftContract();
        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $context = new EventContext(); // no providerName key, no contract provider set
        $context->setContract($contract);
        $event = new CheckoutReturnCompletedEvent(
            $context,
            ReturnResolution::authorized('auth_1', 'ord_1', 1.0, 'EUR'),
        );
        (new ContractPendingTransitioner($repo))->handle($event);

        self::assertSame('draft', $contract->getStateValue());
    }

    public function testFallsBackToContractProvider(): void
    {
        $contract = $this->draftContract();
        $contract->transitionToNotFinished('oxid_ord_1');
        $contract->setProvider('paypal', 'pp_ord_1');

        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $context = new EventContext(); // deliberately no providerName
        $context->setContract($contract);
        $event = new CheckoutReturnCompletedEvent(
            $context,
            ReturnResolution::authorized('auth_1', 'ord_1', 1.0, 'EUR'),
        );
        (new ContractPendingTransitioner($repo))->handle($event);

        self::assertSame('pending', $contract->getStateValue());
    }

    public function testIgnoresUnrelatedEvent(): void
    {
        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->expects(self::never())->method('save');
        (new ContractPendingTransitioner($repo))->handle(new \stdClass());
        self::assertTrue(true); // no exception
    }

    private function draftContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user_1', BasketSnapshot::fromArray(['items' => [], 'totalGross' => 1.0, 'totalNet' => 1.0, 'totalVat' => 0.0, 'currency' => 'EUR']));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        return $contract;
    }

    private function successEvent(PaymentContract $contract, string $providerName): CheckoutReturnCompletedEvent
    {
        $context = new EventContext(['providerName' => $providerName]);
        $context->setContract($contract);
        return new CheckoutReturnCompletedEvent(
            $context,
            ReturnResolution::authorized('auth_1', 'ord_1', 1.0, 'EUR'),
        );
    }
}
