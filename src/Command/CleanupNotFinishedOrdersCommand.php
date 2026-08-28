<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Command;

use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupServiceInterface;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettingsInterface;
use OxidEsales\PaymentBase\Service\Result\NotFinishedOrderCleanupResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Collects abandoned NOT_FINISHED orders from the command line.
 *
 * PSP modules create the order before redirecting the customer to the provider
 * so an order number exists on return. A customer who never returns leaves the
 * order at NOT_FINISHED with its vouchers still spent. Until this command there
 * was no scheduled collector at all — cleanup only ran as a side effect of a
 * customer retrying or of an inbound provider webhook, so a shop with neither
 * accumulated those rows indefinitely.
 */
#[AsCommand(
    name: 'oe:payments:not_finished:cleanup',
    description: 'Cancel abandoned NOT_FINISHED orders older than the configured cleanup period'
)]
class CleanupNotFinishedOrdersCommand extends Command
{
    /**
     * Registered globally by the Enterprise console subscriber. Read, never
     * declared here: declaring it a second time makes every command on EE
     * fail to configure.
     */
    private const SHOP_ID_OPTION = 'shop-id';

    public function __construct(
        private readonly NotFinishedOrderCleanupServiceInterface $cleanupService,
        private readonly NotFinishedOrderCleanupSettingsInterface $settings
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Age threshold in days. Overrides the "Cleanup period" module setting for this run.'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Process at most this many orders in one run.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would be cleaned up without writing anything.'
            )
            ->setHelp(
                <<<'HELP'
use bin/oe-console oe:payments:not_finished:cleanup to cleanup
not_finished orders older than the given amount of days.

The amount of days comes from the "Cleanup period" setting of the
oe_payment_base module, and can be overridden per run with <info>--days</info>.

An order counts as abandoned when it is still at OXTRANSSTATUS = 'NOT_FINISHED',
is not yet stornoed, and is older than that period. For each one the command:

  * marks the order OXSTORNO = 1 and OXTRANSSTATUS = 'CANCELLED' — the row is
    kept, so the order-number sequence stays free of gaps;
  * releases the vouchers the unfinished order was holding;
  * cancels the linked payment contract, unless it is already committed or
    already in a terminal state.

Without <info>--shop-id</info> (Enterprise only) every subshop is processed.
Stock is not restored — that stays the shop's own concern.

<info>Examples:</info>
  bin/oe-console oe:payments:not_finished:cleanup
  bin/oe-console oe:payments:not_finished:cleanup --dry-run
  bin/oe-console oe:payments:not_finished:cleanup --days=30 --limit=500

<info>Daily cron:</info>
  bin/oe-console oe:payments:not_finished:cleanup
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $this->readDays($input);
        if ($days === null) {
            $io->error('The --days option must be a whole number of days, 1 or greater.');

            return self::INVALID;
        }

        $limit = $this->readLimit($input);
        if ($limit === null && $input->getOption('limit') !== null) {
            $io->error('The --limit option must be a whole number, 1 or greater.');

            return self::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $result = $this->cleanupService->cleanup($days, $dryRun, $this->readShopId($input), $limit);
        } catch (Throwable $e) {
            // A cron job that prints success for a sweep which never happened
            // is worse than one that prints nothing.
            $io->error('Cleanup failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->report($io, $result, $days);

        return self::SUCCESS;
    }

    private function report(StyleInterface $io, NotFinishedOrderCleanupResult $result, int $days): void
    {
        if ($result->dryRun) {
            $io->success(sprintf(
                'Dry run: %d unfinished order(s) older than %d day(s) would be cleaned up.',
                $result->scanned,
                $days
            ));

            return;
        }

        $io->success(sprintf(
            'Cleaned up %d of %d unfinished order(s) older than %d day(s): '
            . '%d contract(s) cancelled, %d voucher(s) released.',
            $result->ordersCancelled,
            $result->scanned,
            $days,
            $result->contractsCancelled,
            $result->vouchersReleased
        ));

        if ($result->failed > 0) {
            $io->warning(sprintf(
                '%d order(s) could not be cleaned up and were skipped; see the shop log for details.',
                $result->failed
            ));
        }
    }

    /**
     * @return int|null the period to use, or null if --days was given but unusable
     */
    private function readDays(InputInterface $input): ?int
    {
        $raw = $input->getOption('days');

        if ($raw === null) {
            return $this->settings->getCleanupPeriodDays();
        }

        return $this->readPositiveInt($raw);
    }

    private function readLimit(InputInterface $input): ?int
    {
        $raw = $input->getOption('limit');

        if ($raw === null) {
            return null;
        }

        return $this->readPositiveInt($raw);
    }

    private function readShopId(InputInterface $input): ?int
    {
        if (!$input->hasOption(self::SHOP_ID_OPTION)) {
            return null;
        }

        $raw = $input->getOption(self::SHOP_ID_OPTION);

        return $raw === null ? null : $this->readPositiveInt($raw);
    }

    private function readPositiveInt(mixed $raw): ?int
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value >= 1 ? $value : null;
    }
}
