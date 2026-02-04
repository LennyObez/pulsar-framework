<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;

use function sprintf;
use function str_pad;

use Throwable;

/**
 * Display the status of all migrations.
 */
final class MigrateStatusCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly MigrationRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'migrate:status';
        $this->description = 'Show the status of each migration';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->runner->ensureMigrationTable();
            $allFiles = $this->repository->discover();
            $applied = $this->runner->getApplied();
        } catch (Throwable $e) {
            $output->errorln(sprintf('Failed to read migration status: %s', $e->getMessage()));
            return ExitCode::Error->value;
        }

        if ($allFiles === []) {
            $output->info('No migration files found.');
            return ExitCode::Success->value;
        }

        // Index applied migrations by version
        $appliedByVersion = [];
        foreach ($applied as $record) {
            $appliedByVersion[$record->version] = $record;
        }

        // Header
        $output->writeln(sprintf(
            '  %s  %s  %s  %s  %s',
            str_pad('Version', 16),
            str_pad('Name', 40),
            str_pad('Status', 10),
            str_pad('Batch', 7),
            'Applied At',
        ));

        $output->writeln(sprintf('  %s', str_repeat('-', 100)));

        foreach ($allFiles as $version => $file) {
            if (isset($appliedByVersion[$version])) {
                $record = $appliedByVersion[$version];
                $status = 'Applied';
                $batch = (string) $record->batch;
                $appliedAt = $record->appliedAt->format('Y-m-d H:i:s');
            } else {
                $status = 'Pending';
                $batch = '-';
                $appliedAt = '-';
            }

            $output->writeln(sprintf(
                '  %s  %s  %s  %s  %s',
                str_pad($version, 16),
                str_pad($file->name, 40),
                str_pad($status, 10),
                str_pad($batch, 7),
                $appliedAt,
            ));
        }

        return ExitCode::Success->value;
    }
}
