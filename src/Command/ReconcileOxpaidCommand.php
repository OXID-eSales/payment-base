<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Command;

use OxidEsales\PaymentBase\Reconciliation\OxpaidReconciliationServiceInterface;
use OxidEsales\PaymentBase\Reconciliation\ReconciliationResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Heals orders that show as unpaid although the provider has the money, for every provider that
 * registered a status port. Covers missed or delayed webhooks.
 *
 *   bin/oe-console oe:payments:reconcile-oxpaid                    all providers, last 7 days
 *   bin/oe-console oe:payments:reconcile-oxpaid <orderId>          one order
 *   bin/oe-console oe:payments:reconcile-oxpaid --dry-run --max-age=14 --provider=<name>
 */
#[AsCommand(
    name: 'oe:payments:reconcile-oxpaid',
    description: 'Reconcile OXPAID timestamps with the payment status at the provider',
)]
final class ReconcileOxpaidCommand extends Command
{
    private const DEFAULT_MAX_AGE_DAYS = 7;

    public function __construct(
        private readonly OxpaidReconciliationServiceInterface $reconciliationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('orderId', InputArgument::OPTIONAL, 'OXID id of one order to reconcile')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes')
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Maximum age of orders to check in days', (string) self::DEFAULT_MAX_AGE_DAYS)
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Only this provider (default: all registered)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderId = $input->getArgument('orderId');
        if (is_string($orderId) && $orderId !== '') {
            return $this->reportSingle($io, $this->reconciliationService->reconcileOrder($orderId));
        }

        $maxAgeRaw = $input->getOption('max-age');
        $maxAgeDays = is_numeric($maxAgeRaw) ? (int) $maxAgeRaw : self::DEFAULT_MAX_AGE_DAYS;
        $providerOption = $input->getOption('provider');
        $dryRun = (bool) $input->getOption('dry-run');

        return $this->reconcileBatch($io, is_string($providerOption) ? $providerOption : null, $maxAgeDays, $dryRun);
    }

    private function reportSingle(StyleInterface $io, ReconciliationResult $result): int
    {
        if ($result->action === ReconciliationResult::ACTION_NO_CONTRACT || $result->action === ReconciliationResult::ACTION_ERROR) {
            $io->error($result->reason);

            return Command::FAILURE;
        }
        if ($result->action === ReconciliationResult::ACTION_UPDATED) {
            $io->success(sprintf('OXPAID healed for order "%s".', $result->orderId));

            return Command::SUCCESS;
        }
        $io->note(sprintf('Order "%s": %s', $result->orderId, $result->reason));

        return Command::SUCCESS;
    }

    private function reconcileBatch(StyleInterface $io, ?string $onlyProvider, int $maxAgeDays, bool $dryRun): int
    {
        $io->title('OXPAID Reconciliation');
        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be made');
        }
        $io->text("Checking orders from the last {$maxAgeDays} days...");

        $results = [];
        foreach ($this->selectProviders($onlyProvider) as $provider) {
            $results = array_merge($results, $this->reconciliationService->reconcileAll($provider, $maxAgeDays, $dryRun));
        }
        if ($results === []) {
            $io->success('No unpaid orders with a provider contract found.');

            return Command::SUCCESS;
        }

        return $this->reportBatch($io, $results, $dryRun);
    }

    /** @return list<string> */
    private function selectProviders(?string $onlyProvider): array
    {
        $registered = $this->reconciliationService->providers();

        return $onlyProvider === null ? $registered : array_values(array_intersect($registered, [$onlyProvider]));
    }

    /** @param list<ReconciliationResult> $results */
    private function reportBatch(StyleInterface $io, array $results, bool $dryRun): int
    {
        $io->table(
            ['Order ID', 'Provider order', 'Action', 'Contract', 'Reason'],
            array_map(static fn (ReconciliationResult $result): array => [
                substr($result->orderId, 0, 12) . '...',
                $result->providerOrderId,
                strtoupper($result->action),
                $result->contractUpdated ? 'Yes' : 'No',
                substr($result->reason, 0, 40),
            ], $results)
        );

        $count = fn (string $action): int => count(array_filter(
            $results,
            static fn (ReconciliationResult $result): bool => $result->action === $action
        ));
        $errors = $count(ReconciliationResult::ACTION_ERROR) + $count(ReconciliationResult::ACTION_NO_CONTRACT);
        $updated = $count(ReconciliationResult::ACTION_UPDATED);

        $io->section('Summary');
        $io->text($dryRun
            ? ['Orders found: ' . count($results)]
            : ['Orders checked: ' . count($results), "Updated: {$updated}", "Errors: {$errors}"]);

        if ($errors > 0) {
            $io->warning("Completed with {$errors} error(s). Check logs for details.");

            return Command::FAILURE;
        }
        $io->success($updated > 0 ? "Successfully reconciled {$updated} order(s)." : 'No orders needed reconciliation.');

        return Command::SUCCESS;
    }
}
