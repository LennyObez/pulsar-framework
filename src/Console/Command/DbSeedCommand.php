<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\Seeder\SeederRunner;
use Throwable;

use function count;
use function sprintf;

/**
 * Run database seeders.
 */
final class DbSeedCommand extends Command
{
    public function __construct(
        private readonly SeederRunner $runner,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'db:seed';
        $this->description = 'Run database seeders';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $seederName */
        $seederName = $input->getArgument('name');

        try {
            if ($seederName !== null) {
                $this->runner->runByName($seederName);
                $output->success(sprintf('Seeder "%s" executed successfully.', $seederName));

                return ExitCode::Success->value;
            }

            $executed = $this->runner->runAll();
        } catch (Throwable $e) {
            $output->errorln(sprintf('Seeding failed: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        if ($executed === []) {
            $output->info('No seeders found.');

            return ExitCode::Success->value;
        }

        foreach ($executed as $name) {
            $output->writeln(sprintf('  Seeded: %s', $name));
        }

        $output->newLine();
        $output->success(sprintf('Ran %d seeder(s) successfully.', count($executed)));

        return ExitCode::Success->value;
    }
}
