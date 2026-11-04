<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Command;

use OxidEsales\PaymentBase\Command\ReconcileOxpaidCommand;
use OxidEsales\PaymentBase\Reconciliation\OxpaidReconciliationServiceInterface;
use OxidEsales\PaymentBase\Reconciliation\ReconciliationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Union of the Stripe (batch: options, table, summary) and Mollie (single order) command tests.
 */
#[CoversClass(ReconcileOxpaidCommand::class)]
final class ReconcileOxpaidCommandTest extends TestCase
{
    private OxpaidReconciliationServiceInterface&MockObject $service;
    private ReconcileOxpaidCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->service = $this->createMock(OxpaidReconciliationServiceInterface::class);
        $this->service->method('providers')->willReturn(['acme', 'other']);
        $this->command = new ReconcileOxpaidCommand($this->service);
        $this->tester = new CommandTester($this->command);
    }

    public function testNameDescriptionAndOptions(): void
    {
        $definition = $this->command->getDefinition();

        self::assertSame('oe:payments:reconcile-oxpaid', $this->command->getName());
        self::assertNotEmpty($this->command->getDescription());
        self::assertTrue($definition->hasOption('dry-run'));
        self::assertSame('7', $definition->getOption('max-age')->getDefault());
        self::assertTrue($definition->hasOption('provider'));
        self::assertFalse($definition->getArgument('orderId')->isRequired());
    }

    public function testBatchWithNoUnpaidOrdersSucceeds(): void
    {
        $this->service->method('reconcileAll')->willReturn([]);

        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No unpaid orders', $this->tester->getDisplay());
    }

    public function testBatchRunsEveryRegisteredProviderWithTheDefaultAge(): void
    {
        $asked = [];
        $this->service->method('reconcileAll')->willReturnCallback(
            static function (string $provider, int $age, bool $dryRun) use (&$asked): array {
                $asked[] = [$provider, $age, $dryRun];

                return [];
            }
        );

        $this->tester->execute([]);

        self::assertSame([['acme', 7, false], ['other', 7, false]], $asked);
    }

    public function testBatchHonoursTheProviderMaxAgeAndDryRunOptions(): void
    {
        $this->service->expects(self::once())->method('reconcileAll')->with('other', 14, true)->willReturn([
            new ReconciliationResult('order1', 'pay_1', false, 'dry_run', 'Would check order'),
        ]);

        $exit = $this->tester->execute(['--provider' => 'other', '--max-age' => '14', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('DRY RUN MODE', $this->tester->getDisplay());
        self::assertStringContainsString('last 14 days', $this->tester->getDisplay());
    }

    public function testBatchReportsUpdatedOrdersAndContractUpdates(): void
    {
        $this->service->method('reconcileAll')->willReturnOnConsecutiveCalls(
            [new ReconciliationResult('order1', 'pay_1', true, 'updated', 'OXPAID updated', true)],
            []
        );

        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('UPDATED', $this->tester->getDisplay());
        self::assertStringContainsString('Yes', $this->tester->getDisplay());
        self::assertStringContainsString('Successfully reconciled 1 order', $this->tester->getDisplay());
    }

    public function testBatchShowsSkippedOrders(): void
    {
        $this->service->method('reconcileAll')->willReturnOnConsecutiveCalls(
            [new ReconciliationResult('order1', 'pay_1', false, 'skipped', 'Payment not paid')],
            []
        );

        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('SKIPPED', $this->tester->getDisplay());
        self::assertStringContainsString('No orders needed reconciliation', $this->tester->getDisplay());
    }

    public function testBatchWithErrorsFails(): void
    {
        $this->service->method('reconcileAll')->willReturnOnConsecutiveCalls(
            [new ReconciliationResult('order1', 'pay_1', false, 'error', 'API down')],
            []
        );

        $exit = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('1 error', $this->tester->getDisplay());
    }

    public function testSingleOrderHealedReportsSuccess(): void
    {
        $this->service->expects(self::once())->method('reconcileOrder')->with('order-3')
            ->willReturn(new ReconciliationResult('order-3', 'tr_1', true, 'updated', 'ok'));
        $this->service->expects(self::never())->method('reconcileAll');

        $exit = $this->tester->execute(['orderId' => 'order-3']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('healed', $this->tester->getDisplay());
    }

    public function testSingleOrderAlreadyConsistentReportsNoOpAsSuccess(): void
    {
        $this->service->method('reconcileOrder')
            ->willReturn(new ReconciliationResult('order-4', 'tr_2', false, 'skipped', 'Nothing to reconcile (already consistent)'));

        $exit = $this->tester->execute(['orderId' => 'order-4']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Nothing to reconcile', $this->tester->getDisplay());
    }

    public function testSingleOrderWithoutContractFails(): void
    {
        $this->service->method('reconcileOrder')
            ->willReturn(new ReconciliationResult('order-1', '', false, 'no_contract', 'No contract found for order: order-1'));

        $exit = $this->tester->execute(['orderId' => 'order-1']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No contract found', $this->tester->getDisplay());
    }
}
