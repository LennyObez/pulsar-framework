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

            // The runner wraps whatever `down()` threw in
            // `DatabaseException::migrationFailed()`, whose message names only the version
            // and the direction. The reason lives one link down the chain — including the
            // refusal a framework migration raises rather than drop a table that still
            // holds rows — so printing only the outer message tells an operator that the
            // rollback stopped without telling them what would have been destroyed.
            // `migrate:run` has always printed the cause; this is the same line.
            $previous = $e->getPrevious();

            if ($previous !== null) {
                $output->errorln(sprintf('Caused by: %s', $previous->getMessage()));
            }

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
