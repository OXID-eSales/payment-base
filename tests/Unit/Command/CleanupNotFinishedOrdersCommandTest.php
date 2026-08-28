<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Command;

use OxidEsales\PaymentBase\Command\CleanupNotFinishedOrdersCommand;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupServiceInterface;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettingsInterface;
use OxidEsales\PaymentBase\Service\Result\NotFinishedOrderCleanupResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bin/oe-console oe:payments:not_finished:cleanup`
 *
 * The production caller for the NOT_FINISHED collector. Before it, cleanup
 * only ever ran as a side effect of an inbound Stripe webhook.
 */
final class CleanupNotFinishedOrdersCommandTest extends TestCase
{
    /** @var NotFinishedOrderCleanupServiceInterface&MockObject */
    private NotFinishedOrderCleanupServiceInterface $service;
    /** @var NotFinishedOrderCleanupSettingsInterface&MockObject */
    private NotFinishedOrderCleanupSettingsInterface $settings;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->createMock(NotFinishedOrderCleanupServiceInterface::class);
        $this->settings = $this->createMock(NotFinishedOrderCleanupSettingsInterface::class);
        $this->settings->method('getCleanupPeriodDays')->willReturn(7);

        $command = new CleanupNotFinishedOrdersCommand($this->service, $this->settings);
        (new Application())->add($command);
        $this->tester = new CommandTester($command);
    }

    public function testIsRegisteredUnderTheAgreedName(): void
    {
        $this->assertSame(
            'oe:payments:not_finished:cleanup',
            (new CleanupNotFinishedOrdersCommand($this->service, $this->settings))->getName()
        );
    }

    public function testHelpTellsTheOperatorHowToRunIt(): void
    {
        $help = (new CleanupNotFinishedOrdersCommand($this->service, $this->settings))->getHelp();

        $this->assertStringContainsString(
            'bin/oe-console oe:payments:not_finished:cleanup',
            $help
        );
        $this->assertStringContainsString(
            'not_finished orders older than the given amount of days',
            $help
        );
    }

    public function testUsesTheConfiguredCleanupPeriodByDefault(): void
    {
        $this->service
            ->expects($this->once())
            ->method('cleanup')
            ->with(7, false, null, null)
            ->willReturn($this->cleanupResult(ordersCancelled: 3));

        $exitCode = $this->tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('3', $this->tester->getDisplay());
    }

    public function testTheDaysOptionOverridesTheConfiguredPeriod(): void
    {
        $this->service
            ->expects($this->once())
            ->method('cleanup')
            ->with(30, false, null, null)
            ->willReturn($this->cleanupResult());

        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--days' => '30']));
    }

    public function testDryRunIsForwarded(): void
    {
        $this->service
            ->expects($this->once())
            ->method('cleanup')
            ->with(7, true, null, null)
            ->willReturn($this->cleanupResult(scanned: 5, dryRun: true));

        $this->tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('5', $this->tester->getDisplay());
        $this->assertStringContainsString('dry run', strtolower($this->tester->getDisplay()));
    }

    public function testLimitIsForwarded(): void
    {
        $this->service
            ->expects($this->once())
            ->method('cleanup')
            ->with(7, false, null, 50)
            ->willReturn($this->cleanupResult());

        $this->tester->execute(['--limit' => '50']);
    }

    /**
     * "Older than 0 days" would select the checkout in progress. The command
     * must refuse it before the service is ever asked.
     */
    public function testRejectsANonPositiveDaysOption(): void
    {
        $this->service->expects($this->never())->method('cleanup');

        $this->assertSame(Command::INVALID, $this->tester->execute(['--days' => '0']));
    }

    public function testRejectsANonNumericDaysOption(): void
    {
        $this->service->expects($this->never())->method('cleanup');

        $this->assertSame(Command::INVALID, $this->tester->execute(['--days' => 'soon']));
    }

    /**
     * Reporting a clean sweep for a run that blew up is exactly the false
     * signal a cron job cannot afford.
     */
    public function testReportsFailureWhenTheServiceThrows(): void
    {
        $this->service->method('cleanup')->willThrowException(new RuntimeException('table missing'));

        $exitCode = $this->tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('table missing', $this->tester->getDisplay());
    }

    public function testWarnsWhenSomeOrdersCouldNotBeCleaned(): void
    {
        $this->service->method('cleanup')->willReturn($this->cleanupResult(scanned: 4, ordersCancelled: 3, failed: 1));

        $this->tester->execute([]);

        $this->assertStringContainsString('1', $this->tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    private function cleanupResult(
        int $scanned = 0,
        int $ordersCancelled = 0,
        int $contractsCancelled = 0,
        int $vouchersReleased = 0,
        int $failed = 0,
        bool $dryRun = false
    ): NotFinishedOrderCleanupResult {
        return new NotFinishedOrderCleanupResult(
            scanned: $scanned,
            ordersCancelled: $ordersCancelled,
            contractsCancelled: $contractsCancelled,
            vouchersReleased: $vouchersReleased,
            failed: $failed,
            dryRun: $dryRun
        );
    }
}
