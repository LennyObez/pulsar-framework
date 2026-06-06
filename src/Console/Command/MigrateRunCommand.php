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
 * Run all pending database migrations.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class MigrateRunCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'migrate:run';
        $this->description = 'Run all pending database migrations';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $applied = $this->runner->runPending();
        } catch (Throwable $e) {
            $output->errorln(sprintf('Migration failed: %s', $e->getMessage()));
            if ($e->getPrevious() !== null) {
                $output->errorln(sprintf('Caused by: %s', $e->getPrevious()->getMessage()));
            }
            return ExitCode::Error->value;
        }

        if ($applied === []) {
            $output->info('Nothing to migrate. All migrations are up to date.');
            return ExitCode::Success->value;
        }

        foreach ($applied as $version) {
            $output->writeln(sprintf('  Migrated: %s', $version));
        }

        $output->newLine();
        $output->success(sprintf('Ran %d migration(s) successfully.', count($applied)));

        return ExitCode::Success->value;
    }
}
