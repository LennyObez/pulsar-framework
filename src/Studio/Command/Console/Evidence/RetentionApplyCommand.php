<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console\Evidence;

use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Command\Console\JsonOutputHelper;
use Pulsar\Studio\Console\Retention\RetentionEnforcerInterface;

use function sprintf;

/**
 * Applies the retention policy to the Studio evidence store.
 */
#[Internal]
final class RetentionApplyCommand extends Command
{
    public function __construct(
        private readonly RetentionEnforcerInterface $enforcer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:console:evidence:retention:apply';
        $this->description = 'Apply retention policy to evidence store';
        $this->addOption('json', 'Output as JSON', 'j');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isJson = $input->hasOption('json');

        $result = $this->enforcer->enforce();

        if ($isJson) {
            $output->writeln(JsonOutputHelper::encode('studio:console:evidence:retention:apply', true, $result));

            return ExitCode::Success->value;
        }

        $output->writeln('Retention Policy Applied');
        $output->writeln(str_repeat('=', 40));
        $output->writeln(sprintf('  Events deleted: %d', $result['events_deleted']));
        $output->writeln(sprintf('  Vacuum run:     %s', $result['vacuum_run'] ? 'yes' : 'no'));

        return ExitCode::Success->value;
    }
}
