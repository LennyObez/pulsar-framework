<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\Migration\MigrationRunner;
use Throwable;

use function count;
use function sprintf;

/**
 * Rollback database migrations.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class MigrateRollbackCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'migrate:rollback';
        $this->description = 'Rollback the last batch of migrations';
        $this->addOption('all', 'Rollback all migrations (reset)');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->hasOption('all')) {
                $rolledBack = $this->runner->reset();
            } else {
                $rolledBack = $this->runner->rollbackLastBatch();
            }
        } catch (Throwable $e) {
            $output->errorln(sprintf('Rollback failed: %s', $e->getMessage()));
            return ExitCode::Error->value;
        }

        if ($rolledBack === []) {
            $output->info('Nothing to rollback.');
            return ExitCode::Success->value;
        }

        foreach ($rolledBack as $version) {
            $output->writeln(sprintf('  Rolled back: %s', $version));
        }

        $output->newLine();
        $output->success(sprintf('Rolled back %d migration(s) successfully.', count($rolledBack)));

        return ExitCode::Success->value;
    }
}
